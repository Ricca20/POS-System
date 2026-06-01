<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreStockTransferRequest;
use App\Http\Responses\JsonError;
use App\PurchaseLine;
use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfer endpoints for the desktop SPA (task 4.4).
 *
 *   GET  /api/v1/stock/transfer   -> index()
 *   POST /api/v1/stock/transfer   -> store()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so the controller
 * resolves the active business id via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * Stock transfers are tracked with two paired `transactions` rows in the
 * legacy schema:
 *   - `type = 'sell_transfer'`  at the source location (this is the row
 *     `index()` lists, matching the legacy convention).
 *   - `type = 'purchase_transfer'` at the destination location, linked
 *     back to the sell_transfer via `transfer_parent_id`.
 *
 * Each line item is mirrored as two `purchase_lines` rows (one per
 * transaction), so quantity tracking remains symmetric.
 *
 * Permission gating mirrors the legacy `StockTransferController` which
 * uses `purchase.create`. Validation lives in the form request
 * (`StoreStockTransferRequest`), including the `from_location_id !=
 * to_location_id` constraint.
 *
 * Validates: R8.1, R8.2.
 */
class StockTransferApiController extends Controller
{
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 15;

    /**
     * GET /api/v1/stock/transfer
     *
     * Paginated list of `sell_transfer` rows for the authenticated
     * business. The mirrored `purchase_transfer` rows are intentionally
     * not surfaced as separate items — they are an implementation detail
     * of how the destination side of the transfer is tracked.
     */
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('purchase.create')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $paginator = Transaction::where('business_id', $businessId)
            ->where('type', 'sell_transfer')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/stock/transfer
     *
     * Atomically:
     *   1. Creates a `sell_transfer` Transaction at `from_location_id`.
     *   2. Creates a paired `purchase_transfer` Transaction at
     *      `to_location_id` with `transfer_parent_id = sell_transfer.id`.
     *   3. For each product line, creates two `purchase_lines` rows
     *      (one per transaction) and applies symmetric stock movement:
     *      decrement at the source, increment at the destination.
     *
     * Wrapped in `DB::transaction` so any failure rolls back both
     * transaction rows, every line insert, and every qty change. Stock
     * reads use `lockForUpdate()` to serialise concurrent writes against
     * the same (variation, location) pair.
     */
    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $businessId = pos_context('business.id');
        $userId = auth()->id();

        $totalAmount = 0.0;
        foreach ($data['products'] as $line) {
            $totalAmount += (float) $line['quantity'] * (float) $line['unit_price'];
        }

        $shippingCharges = (float) ($data['shipping_charges'] ?? 0);
        $finalTotal = $totalAmount + $shippingCharges;

        $sellTransfer = DB::transaction(function () use (
            $data,
            $businessId,
            $userId,
            $finalTotal,
            $shippingCharges
        ) {
            $sell = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int) $data['from_location_id'],
                'type' => 'sell_transfer',
                'status' => 'final',
                'transaction_date' => $data['transaction_date'],
                'ref_no' => $data['ref_no'] ?? null,
                'final_total' => $finalTotal,
                'shipping_charges' => $shippingCharges,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            $purchase = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int) $data['to_location_id'],
                'type' => 'purchase_transfer',
                'status' => 'final',
                'transaction_date' => $data['transaction_date'],
                'ref_no' => $data['ref_no'] ?? null,
                'final_total' => $finalTotal,
                'shipping_charges' => $shippingCharges,
                'transfer_parent_id' => $sell->id,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['products'] as $line) {
                $productId = (int) $line['product_id'];
                $variationId = (int) $line['variation_id'];
                $quantity = (float) $line['quantity'];
                $unitPrice = (float) $line['unit_price'];

                PurchaseLine::create([
                    'transaction_id' => $sell->id,
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'purchase_price' => $unitPrice,
                    'purchase_price_inc_tax' => $unitPrice,
                ]);

                PurchaseLine::create([
                    'transaction_id' => $purchase->id,
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'quantity' => $quantity,
                    'purchase_price' => $unitPrice,
                    'purchase_price_inc_tax' => $unitPrice,
                ]);

                // Source location: decrement.
                $this->adjustStock($productId, $variationId, (int) $data['from_location_id'], -1 * $quantity);
                // Destination location: increment.
                $this->adjustStock($productId, $variationId, (int) $data['to_location_id'], $quantity);
            }

            return $sell;
        });

        return response()->json(['data' => $sellTransfer->fresh()], 201);
    }

    /**
     * Atomic upsert helper: applies `delta` to the qty_available of the
     * (product, variation, location) row, creating it if missing. Must
     * be called inside an open `DB::transaction`.
     */
    private function adjustStock(int $productId, int $variationId, int $locationId, float $delta): void
    {
        $row = VariationLocationDetails::where('variation_id', $variationId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            VariationLocationDetails::create([
                'product_id' => $productId,
                'variation_id' => $variationId,
                'location_id' => $locationId,
                'qty_available' => $delta,
            ]);

            return;
        }

        $row->qty_available = (float) $row->qty_available + $delta;
        $row->save();
    }
}
