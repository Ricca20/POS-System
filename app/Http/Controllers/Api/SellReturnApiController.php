<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSellReturnRequest;
use App\Http\Resources\PosSaleResource;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\TransactionSellLine;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sell-return endpoints for the desktop SPA (task 5.5).
 *
 *   GET  /api/v1/sell-returns   -> index()  (paginated list)
 *   POST /api/v1/sell-returns   -> store()  (create return)
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. Every query is
 * scoped to `pos_context('business.id')`; cross-business reads return
 * 404 (no leak), cross-business writes are rejected at the form-request
 * stage with a 422 envelope.
 *
 * Permission gating uses the legacy `access_sell_return` permission key,
 * which mirrors the original `SellReturnController`. `index` checks
 * inline; `store` checks via `StoreSellReturnRequest::authorize`.
 *
 * Stock side effects
 * ------------------
 * Creating a sell-return increments `variation_location_details.qty_available`
 * at the *parent sale's* location for every returned line — the inverse
 * of the decrement performed by `PosApiController::store`. The whole
 * insert + stock change runs inside a single `DB::transaction` so a
 * partial failure rolls everything back.
 *
 * Validates: R8.1, R8.2.
 */
class SellReturnApiController extends Controller
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 15;

    /**
     * GET /api/v1/sell-returns
     *
     * Paginated list of sell-returns (`type='sell_return'`) for the
     * authenticated user's business, eager-loading the contact so the
     * SPA can render the customer column.
     *
     * Query params:
     *   from_date — ISO date; transaction_date >= from_date.
     *   to_date   — ISO date; transaction_date <= to_date 23:59:59.
     *   per_page  — page size (capped at MAX_PER_PAGE).
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! auth()->user()->can('access_sell_return')) {
            return JsonError::forbidden();
        }

        $validator = Validator::make($request->query(), [
            'from_date' => ['sometimes', 'nullable', 'date'],
            'to_date' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');

        $query = Transaction::with('contact')
            ->where('business_id', $businessId)
            ->where('type', 'sell_return');

        if ($request->filled('from_date')) {
            $query->whereDate('transaction_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('transaction_date', '<=', $request->query('to_date'));
        }

        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $paginator = $query->orderByDesc('transaction_date')->paginate($perPage);

        return PosSaleResource::collection($paginator);
    }

    /**
     * POST /api/v1/sell-returns
     *
     * Create a sell-return tied to a parent sale. The form request
     * already validated:
     *   - `parent_sale_id` exists, belongs to the auth business, and
     *     has `type='sell'` (so a SPA cannot return against a sale
     *     from a different business or a non-sell transaction).
     *   - At least one product line with a positive quantity and
     *     non-negative unit_price.
     *
     * Persistence:
     *   1) Look up the parent sale to copy `location_id` and
     *      `contact_id`. The return inherits both so it lists alongside
     *      the parent in customer-history reports.
     *   2) Compute total = sum(quantity * unit_price). The return is
     *      recorded as `payment_status='paid'` because the customer
     *      has already paid the parent sale and is now receiving a
     *      refund — payment journaling is deferred to a future task.
     *   3) Insert the `transactions` row with `type='sell_return'`,
     *      `status='final'`, and `return_parent_id = parent_sale_id`.
     *   4) Insert one `transaction_sell_lines` row per returned line
     *      with positive quantity (consistent with the legacy schema).
     *   5) Increment `variation_location_details.qty_available` at the
     *      parent's location for each line — return adds stock back.
     */
    public function store(StoreSellReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $businessId = (int) pos_context('business.id');
        $userId = (int) auth()->id();

        $parent = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->find((int) $data['parent_sale_id']);

        // Defensive 404 — should be unreachable because the form
        // request's `Rule::exists` already enforces the same scope,
        // but guard against a TOCTOU race where the parent gets
        // deleted between validation and the controller body.
        if ($parent === null) {
            return JsonError::notFound('Parent sale not found.');
        }

        $totalBeforeTax = 0.0;
        foreach ($data['products'] as $line) {
            $totalBeforeTax += (float) $line['quantity'] * (float) $line['unit_price'];
        }
        $finalTotal = $totalBeforeTax;

        $return = DB::transaction(function () use (
            $data,
            $parent,
            $businessId,
            $userId,
            $totalBeforeTax,
            $finalTotal,
        ) {
            $row = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int) $parent->location_id,
                'type' => 'sell_return',
                'status' => 'final',
                'payment_status' => 'paid',
                'return_parent_id' => (int) $parent->id,
                'transaction_date' => $data['transaction_date'],
                'contact_id' => $parent->contact_id !== null ? (int) $parent->contact_id : null,
                'total_before_tax' => $totalBeforeTax,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'shipping_charges' => 0,
                'final_total' => $finalTotal,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['products'] as $line) {
                $unitPrice = (float) $line['unit_price'];

                TransactionSellLine::create([
                    'transaction_id' => $row->id,
                    'product_id' => (int) $line['product_id'],
                    'variation_id' => (int) $line['variation_id'],
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_price_inc_tax' => $unitPrice,
                    'item_tax' => 0,
                    'line_discount_amount' => 0,
                ]);

                $this->incrementStock(
                    productId: (int) $line['product_id'],
                    variationId: (int) $line['variation_id'],
                    locationId: (int) $parent->location_id,
                    quantity: (float) $line['quantity'],
                );
            }

            return $row;
        });

        $return->load(['sell_lines']);

        return (new PosSaleResource($return))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Increment qty_available for the given (variation, location).
     * Creates the row if missing — symmetric to `decrementStock` in
     * `PosApiController`. Must be called inside an open
     * `DB::transaction` so the `lockForUpdate()` row lock serialises
     * concurrent stock writes.
     */
    private function incrementStock(int $productId, int $variationId, int $locationId, float $quantity): void
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
                'qty_available' => $quantity,
            ]);

            return;
        }

        $row->qty_available = (float) $row->qty_available + $quantity;
        $row->save();
    }
}
