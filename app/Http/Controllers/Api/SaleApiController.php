<?php

namespace App\Http\Controllers\Api;

use App\Business;
use App\Http\Controllers\Controller;
use App\Http\Resources\PosSaleResource;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\TransactionSellLine;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Sales-management endpoints for the desktop SPA (task 5.5).
 *
 *   GET    /api/v1/sales                  -> index()       (final sales list)
 *   GET    /api/v1/sales/drafts           -> drafts()      (held / non-quotation drafts)
 *   GET    /api/v1/sales/quotations       -> quotations()  (drafts flagged is_quotation=1)
 *   GET    /api/v1/sales/{id}             -> show()        (full PosSaleResource)
 *   POST   /api/v1/sales/{id}/duplicate   -> duplicate()   (clones into a draft)
 *   DELETE /api/v1/sales/{id}             -> destroy()     (with stock restock)
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` (see `routes/api.php`)
 * so business id resolves via `pos_context()` (R8.5) and no controller path
 * touches `session()` (R8.4).
 *
 * Cross-business isolation
 * ------------------------
 * Every query is scoped to `pos_context('business.id')`. Cross-business
 * reads / writes return 404 (no leak) — same convention used by
 * `BusinessLocationApiController`, `ProductApiController`, and
 * `PosApiController::update`.
 *
 * Permission gating
 * -----------------
 * Each method performs an inline `auth()->user()->can(...)` check at the
 * top and returns `JsonError::forbidden()` on miss:
 *   - `index`/`show`/`drafts`/`quotations` — `sell.view`.
 *   - `duplicate`                          — `sell.create`.
 *   - `destroy`                            — `sell.delete`.
 *
 * `PosApiController::recentTransactions` already returns the auth user's
 * own recent sales (`created_by = auth()->id()`); this controller serves
 * the *broader* sales list (every sale of the business) plus the related
 * draft/quotation lists, duplicate, and destroy flows.
 *
 * Validates: R8.1, R8.2.
 */
class SaleApiController extends Controller
{
    /**
     * Maximum page size accepted via the `?per_page=` query param.
     * Matches the convention used in `ProductApiController`.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default page size when the caller does not supply `?per_page=`.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * GET /api/v1/sales
     *
     * Paginated list of finalised sales for the authenticated user's
     * business. Drafts and quotations are excluded — they are surfaced
     * via the dedicated `drafts()` and `quotations()` endpoints.
     *
     * Each row goes through `PosSaleResource` *without* eager-loading
     * `sell_lines` / `payment_lines`, so the list payload stays tight
     * (the resource emits keys via `whenLoaded()` only).
     *
     * Query params:
     *   q              — invoice_no LIKE OR contact name LIKE.
     *   location_id    — exact match (must belong to auth business).
     *   payment_status — paid|partial|due.
     *   from_date      — ISO date; transaction_date >= from_date.
     *   to_date        — ISO date; transaction_date <= to_date 23:59:59.
     *   per_page       — page size (capped at MAX_PER_PAGE).
     *
     * Permission: `sell.view`.
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $validator = $this->validateListQuery($request);
        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');

        $query = Transaction::with('contact')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final');

        $this->applyCommonFilters($query, $request);

        $perPage = $this->resolvePerPage($request);
        $paginator = $query->orderByDesc('transaction_date')->paginate($perPage);

        return PosSaleResource::collection($paginator);
    }

    /**
     * GET /api/v1/sales/{id}
     *
     * Full PosSaleResource shape with `sell_lines`, `payment_lines`,
     * `contact`, and `location` eager-loaded so the SPA can render the
     * sale detail view without a follow-up fetch.
     *
     * Cross-business / non-sell ids return 404.
     */
    public function show(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $sale = Transaction::with(['sell_lines', 'payment_lines', 'contact', 'location'])
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->find($id);

        if ($sale === null) {
            return JsonError::notFound('Sale not found.');
        }

        return (new PosSaleResource($sale))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/v1/sales/drafts
     *
     * Held sales — `status='draft'` AND (`is_quotation = 0` OR
     * `is_quotation IS NULL`). Quotations are excluded; they live at
     * `quotations()`.
     *
     * The `is_quotation` filter uses `whereNested(... orWhereNull ...)`
     * so the legacy schema's NULL default counts as "not a quotation"
     * even on rows that pre-date the column being added.
     *
     * Permission: `sell.view`.
     */
    public function drafts(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $validator = $this->validateListQuery($request);
        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');

        $query = Transaction::with('contact')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'draft')
            ->where(function ($q) {
                $q->where('is_quotation', 0)->orWhereNull('is_quotation');
            });

        $this->applyCommonFilters($query, $request);

        $perPage = $this->resolvePerPage($request);
        $paginator = $query->orderByDesc('transaction_date')->paginate($perPage);

        return PosSaleResource::collection($paginator);
    }

    /**
     * GET /api/v1/sales/quotations
     *
     * Quotation drafts — `status='draft'` AND `is_quotation = 1`.
     *
     * Permission: `sell.view`.
     */
    public function quotations(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $validator = $this->validateListQuery($request);
        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');

        $query = Transaction::with('contact')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'draft')
            ->where('is_quotation', 1);

        $this->applyCommonFilters($query, $request);

        $perPage = $this->resolvePerPage($request);
        $paginator = $query->orderByDesc('transaction_date')->paginate($perPage);

        return PosSaleResource::collection($paginator);
    }

    /**
     * POST /api/v1/sales/{id}/duplicate
     *
     * Clone an existing sale into a fresh `status='draft'` row with a
     * new `invoice_no` and copied sell_lines. Payments are NOT copied
     * (a draft starts unpaid). Stock is NOT decremented — drafts do
     * not reserve stock in the legacy schema.
     *
     * Permission: `sell.create`.
     *
     * Returns 201 with the duplicated sale projected through
     * `PosSaleResource` (with `sell_lines` and `payment_lines`
     * eager-loaded so the SPA can render the new draft without a
     * follow-up fetch — `payment_lines` will be an empty array).
     */
    public function duplicate(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('sell.create')) {
            return JsonError::forbidden();
        }

        $businessId = (int) pos_context('business.id');
        $userId = (int) auth()->id();

        $source = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->find($id);

        if ($source === null) {
            return JsonError::notFound('Sale not found.');
        }

        $newSale = DB::transaction(function () use ($source, $userId) {
            // Carry forward every column except identity, audit
            // timestamps, and `payment_status` (a draft starts with no
            // payments, so payment_status is recomputed below).
            $attrs = $source->getAttributes();
            unset(
                $attrs['id'],
                $attrs['created_at'],
                $attrs['updated_at'],
                $attrs['payment_status'],
            );

            $clone = new Transaction;
            $clone->forceFill($attrs);

            // Apply the duplicate-specific overrides.
            $clone->status = 'draft';
            $clone->is_quotation = 0;
            $clone->payment_status = null;
            $clone->created_by = $userId;
            $clone->transaction_date = now();
            $clone->invoice_no = $this->generateInvoiceNo();

            $clone->save();

            // Copy each sell_line into a row pointing at the new
            // transaction. Payments are intentionally NOT copied —
            // see method docblock.
            $sourceLines = TransactionSellLine::where('transaction_id', $source->id)->get();
            foreach ($sourceLines as $line) {
                TransactionSellLine::create([
                    'transaction_id' => $clone->id,
                    'product_id' => (int) $line->product_id,
                    'variation_id' => (int) $line->variation_id,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'unit_price_inc_tax' => (float) $line->unit_price_inc_tax,
                    'tax_id' => $line->tax_id !== null ? (int) $line->tax_id : null,
                    'item_tax' => (float) $line->item_tax,
                    'line_discount_type' => $line->line_discount_type,
                    'line_discount_amount' => (float) $line->line_discount_amount,
                ]);
            }

            return $clone;
        });

        $newSale->load(['sell_lines', 'payment_lines']);

        return (new PosSaleResource($newSale))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/v1/sales/{id}
     *
     * Hard-delete a sale and restore its stock impact. The full flow
     * runs inside a single `DB::transaction`:
     *   1) For each sell_line, increment qty_available at the sale's
     *      location (`incrementStock` mirrors `PosApiController` and
     *      uses `lockForUpdate()` for write-safety).
     *   2) Delete `transaction_payments` rows for the sale.
     *   3) Delete `transaction_sell_lines` rows for the sale.
     *   4) Delete the `transactions` row itself.
     *
     * The `transaction_edit_days` window applies only to *finalised*
     * sales — drafts and quotations are deletable without a window
     * check (the legacy POS allows clearing held / quote rows freely).
     *
     * Permission: `sell.delete`.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('sell.delete')) {
            return JsonError::forbidden();
        }

        $businessId = (int) pos_context('business.id');

        $sale = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->find($id);

        if ($sale === null) {
            return JsonError::notFound('Sale not found.');
        }

        if ($sale->status === 'final') {
            $editDays = $this->editWindowDays($businessId);
            $txDate = $sale->transaction_date instanceof \DateTimeInterface
                ? Carbon::instance($sale->transaction_date)
                : Carbon::parse($sale->transaction_date);
            $daysSince = $txDate->diffInDays(now(), false);

            if ($daysSince > $editDays) {
                return JsonError::forbidden(
                    'edit_window_expired',
                    "Sales older than {$editDays} days cannot be deleted.",
                );
            }
        }

        DB::transaction(function () use ($sale) {
            $locationId = (int) $sale->location_id;
            $sellLines = TransactionSellLine::where('transaction_id', $sale->id)->get();

            foreach ($sellLines as $line) {
                $this->incrementStock(
                    productId: (int) $line->product_id,
                    variationId: (int) $line->variation_id,
                    locationId: $locationId,
                    quantity: (float) $line->quantity,
                );
            }

            DB::table('transaction_payments')->where('transaction_id', $sale->id)->delete();
            DB::table('transaction_sell_lines')->where('transaction_id', $sale->id)->delete();
            DB::table('transactions')->where('id', $sale->id)->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * Validate the list endpoints' shared query-param shape. Returns
     * the validator so callers can fail-fast on `->fails()` and emit
     * the canonical 422 envelope.
     */
    private function validateListQuery(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make($request->query(), [
            'q' => ['sometimes', 'nullable', 'string'],
            'location_id' => ['sometimes', 'nullable', 'integer'],
            'payment_status' => ['sometimes', 'nullable', 'in:paid,partial,due'],
            'from_date' => ['sometimes', 'nullable', 'date'],
            'to_date' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
    }

    /**
     * Apply the q / location_id / payment_status / date-range filters
     * shared by `index`, `drafts`, and `quotations`. Mutates the
     * supplied query builder in place; returns void.
     */
    private function applyCommonFilters($query, Request $request): void
    {
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($sub) use ($like) {
                $sub->where('invoice_no', 'like', $like)
                    ->orWhereHas('contact', function ($c) use ($like) {
                        $c->where('name', 'like', $like);
                    });
            });
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', (int) $request->query('location_id'));
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', (string) $request->query('payment_status'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('transaction_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('transaction_date', '<=', $request->query('to_date'));
        }
    }

    /**
     * Resolve the page size from `?per_page=`, capped at MAX_PER_PAGE
     * and falling back to DEFAULT_PER_PAGE for missing / non-positive
     * values.
     */
    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        return $perPage;
    }

    /**
     * Generate a fresh invoice number using the same `INV-YYYYMMDD-NNNNNN`
     * pattern that `PosApiController::store` uses for placeholder
     * invoices. Sortable + unique-enough for the SPA's lists; the full
     * `InvoiceScheme` integration is deferred.
     */
    private function generateInvoiceNo(): string
    {
        return 'INV-'.now()->format('Ymd').'-'.str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * Resolve the configured `transaction_edit_days` for the active
     * business, defaulting to 30 when the column is missing or null.
     * Mirrors the helper in `PosApiController`.
     */
    private function editWindowDays(int $businessId): int
    {
        try {
            $business = Business::find($businessId);
            $value = $business?->transaction_edit_days;

            if ($value === null) {
                return 30;
            }

            return max(0, (int) $value);
        } catch (\Throwable $e) {
            return 30;
        }
    }

    /**
     * Increment qty_available for the given (variation, location).
     * Creates the row if missing — matches the symmetric `incrementStock`
     * helper in `PosApiController` and keeps stock arithmetic correct
     * even for sales whose VLD row was pruned later.
     *
     * Must be called inside an open `DB::transaction` so the
     * `lockForUpdate()` row lock serialises concurrent stock writes.
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
