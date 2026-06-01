<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreStockAdjustmentRequest;
use App\Http\Responses\JsonError;
use App\PurchaseLine;
use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustment endpoints for the desktop SPA (task 4.4).
 *
 *   GET  /api/v1/stock/adjustment   -> index()
 *   POST /api/v1/stock/adjustment   -> store()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so the controller
 * resolves the active business id via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * Permission gating mirrors the legacy `StockAdjustmentController` which
 * gates both list and create behind `purchase.create`. The store flow
 * matches the legacy schema convention: stock adjustments are tracked in
 * the `transactions` table with `type = 'stock_adjustment'`, and each
 * line item is persisted to the `purchase_lines` table (yes, even though
 * the operation is a stock decrease — that is the legacy convention).
 *
 * Scope deferred from the legacy controller:
 *   - Lot numbers, sub-units, expiry tracking.
 *   - Reference-number generation via `productUtil->setAndGetReferenceCount`
 *     (the SPA can supply its own ref_no; if omitted we leave it null).
 *   - `productUtil->mapPurchaseSell` (FIFO/LIFO mapping for cost basis)
 *     and the `StockAdjustmentCreatedOrModified` event.
 *   - Edit / destroy flows.
 *
 * Validates: R8.1, R8.2.
 */
class StockAdjustmentApiController extends Controller
{
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 15;

    /**
     * GET /api/v1/stock/adjustment
     *
     * Paginated list of stock adjustment transactions for the authenticated
     * business. Permission gating mirrors `purchase.create` (legacy parity).
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
            ->where('type', 'stock_adjustment')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Hand-roll the pagination envelope so it matches the shape used
        // by `ProductResource::collection` (data + links + meta) without
        // creating a dedicated resource class — adjustments are a thin
        // pass-through of the underlying transaction row for now.
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
     * POST /api/v1/stock/adjustment
     *
     * Atomically:
     *   1. Creates a `transactions` row with `type = 'stock_adjustment'`.
     *   2. Creates one `purchase_lines` row per product line, linked to
     *      the new transaction.
     *   3. Decrements `variation_location_details.qty_available` for each
     *      (variation_id, location_id) by the line's quantity.
     *
     * The whole flow runs inside a `DB::transaction(function () { ... })`
     * so a partial failure rolls back the transaction row, the line
     * inserts, and any qty decrements together. The `variation_location_details`
     * read uses `lockForUpdate()` so concurrent stock writes serialise
     * on the row rather than racing.
     *
     * Authorization is enforced inside `StoreStockAdjustmentRequest::authorize`,
     * so this method runs only for callers with `purchase.create`.
     */
    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $businessId = pos_context('business.id');
        $userId = auth()->id();

        $totalAmount = 0.0;
        foreach ($data['products'] as $line) {
            $totalAmount += (float) $line['quantity'] * (float) $line['unit_price'];
        }

        $created = DB::transaction(function () use ($data, $businessId, $userId, $totalAmount) {
            $transaction = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int) $data['location_id'],
                'type' => 'stock_adjustment',
                'status' => 'final',
                'transaction_date' => $data['transaction_date'],
                'ref_no' => $data['ref_no'] ?? null,
                'adjustment_type' => $data['adjustment_type'],
                'final_total' => $totalAmount,
                'total_amount_recovered' => 0,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['products'] as $line) {
                PurchaseLine::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => (int) $line['product_id'],
                    'variation_id' => (int) $line['variation_id'],
                    'quantity' => (float) $line['quantity'],
                    'purchase_price' => (float) $line['unit_price'],
                    'purchase_price_inc_tax' => (float) $line['unit_price'],
                ]);

                $this->adjustStock(
                    productId: (int) $line['product_id'],
                    variationId: (int) $line['variation_id'],
                    locationId: (int) $data['location_id'],
                    delta: -1 * (float) $line['quantity'],
                );
            }

            return $transaction;
        });

        return response()->json(['data' => $created->fresh()], 201);
    }

    /**
     * Atomically apply a `delta` to the qty_available of the given
     * (product, variation, location) tuple. Creates the row when missing
     * (the legacy code preserves the negative-on-create semantics for
     * decrements, so we follow suit).
     *
     * Must be called inside an open `DB::transaction` — the
     * `lockForUpdate()` row lock is released only at commit/rollback.
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
