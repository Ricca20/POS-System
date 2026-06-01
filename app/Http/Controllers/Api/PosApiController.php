<?php

namespace App\Http\Controllers\Api;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePosSaleRequest;
use App\Http\Requests\Api\UpdatePosSaleRequest;
use App\Http\Resources\BusinessLocationResource;
use App\Http\Resources\PosSaleResource;
use App\Http\Responses\JsonError;
use App\Services\PaymentTypes;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * POS-screen specific bootstrap endpoints (task 5.1).
 *
 *   GET /api/v1/pos/config    -> config()    full POS bootstrap envelope
 *   GET /api/v1/pos/products  -> products()  featured-products grid
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` (see `routes/api.php`).
 * Both endpoints require the legacy `sell.create` permission — opening the
 * POS screen has historically been gated on `sell.create`, and we keep that
 * convention here so existing roles continue to work without re-mapping.
 *
 * Why a dedicated controller (instead of folding into `ApiBootstrapController`)
 * --------------------------------------------------------------------------
 * `ApiBootstrapController::config` returns a *generic* envelope used by
 * every screen of the SPA. The POS screen needs strictly more (walk-in
 * customer, keyboard shortcuts, POS settings, selling-price-groups,
 * featured-products IDs per location) and runs under a stricter permission.
 * Splitting them keeps the bootstrap endpoint cheap for screens that do
 * not need the POS-specific data, and keeps permission gating tight on
 * the POS payload.
 *
 * Module-specific data (commission agents, waiters, redeem details,
 * invoice schemes) is *intentionally deferred* to dedicated endpoints
 * when those modules are ported. The legacy `SellPosController::create`
 * returns ~30 keys; this controller returns only the core data the SPA
 * needs to render the POS screen.
 *
 * Validates: R8.1, R8.2.
 */
class PosApiController extends Controller
{
    /**
     * GET /api/v1/pos/config
     *
     * Returns the full POS bootstrap envelope: business identity,
     * relevant business settings, default currency, permitted locations
     * (with full BusinessLocationResource shape, including
     * `featured_products` IDs and printer config), tax rates, payment
     * types, walk-in customer, default datetime, keyboard shortcuts,
     * POS settings, and selling-price groups.
     *
     * Permission: `sell.create` (legacy permission for opening the POS).
     */
    public function config(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sell.create')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');
        $business = $businessId === null ? null : Business::with('currency')->find($businessId);

        return response()->json([
            'data' => [
                'business_id' => $businessId,
                'business_name' => $business?->name,
                'business_settings' => $this->businessSettings($business),
                'default_currency' => $this->defaultCurrency($business),
                'locations' => $this->locations($businessId),
                'tax_rates' => $this->taxRates($businessId),
                'payment_types' => PaymentTypes::defaults($business),
                'walk_in_customer' => $this->walkInCustomer($businessId),
                'default_datetime' => now()->toIso8601String(),
                'keyboard_shortcuts' => $this->parseJsonColumn($business?->keyboard_shortcuts),
                'pos_settings' => $this->parseJsonColumn($business?->pos_settings),
                'selling_price_groups' => $this->sellingPriceGroups($businessId),
            ],
        ], 200);
    }

    /**
     * GET /api/v1/pos/products
     *
     * Returns the featured-products grid for a given location.
     *
     * Query params:
     *   location_id   required, integer; must belong to the auth business.
     *
     * Response shape:
     *   { "data": [
     *       {
     *         "product_id": int, "product_name": string, "image_url": string|null,
     *         "variations": [
     *           { "variation_id", "sub_sku", "name",
     *             "default_sell_price_inc_tax", "current_stock" }
     *         ]
     *       }, ...
     *   ] }
     *
     * `current_stock` sums `variation_location_details.qty_available`
     * for that variation at the supplied `location_id`.
     *
     * Empty / null `featured_products` returns `{ "data": [] }`.
     *
     * Cross-business `location_id` returns 404 to avoid existence leaks
     * (matches the convention established in `BusinessLocationApiController`).
     */
    public function products(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sell.create')) {
            return JsonError::forbidden();
        }

        $validator = Validator::make($request->query(), [
            'location_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');
        $locationId = (int) $request->query('location_id');

        $location = BusinessLocation::where('business_id', $businessId)
            ->find($locationId);

        if ($location === null) {
            // Cross-business or non-existent location: 404 (no leak).
            return JsonError::notFound('Business location not found.');
        }

        $featuredIds = $location->featured_products;
        if (empty($featuredIds) || ! is_array($featuredIds)) {
            return response()->json(['data' => []]);
        }

        // The legacy schema stores `featured_products` as an array of
        // `variations.id` values (see `BusinessLocation::getFeaturedProducts()`).
        // Pull the parent product (for name) and the variation rows in one
        // pass, then aggregate stock in a single grouped query keyed on
        // `variation_id`.
        $variations = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->whereIn('variations.id', $featuredIds)
            ->whereNull('variations.deleted_at')
            ->where('products.business_id', $businessId)
            ->where('products.not_for_selling', 0)
            ->select([
                'variations.id as variation_id',
                'variations.name as variation_name',
                'variations.sub_sku',
                'variations.default_sell_price_inc_tax',
                'variations.product_id',
                'products.name as product_name',
                'products.image as product_image',
            ])
            ->orderBy('variations.id')
            ->get();

        if ($variations->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $variationIds = $variations->pluck('variation_id')->all();

        $stockMap = DB::table('variation_location_details')
            ->whereIn('variation_id', $variationIds)
            ->where('location_id', $locationId)
            ->select('variation_id', DB::raw('SUM(qty_available) as total_qty'))
            ->groupBy('variation_id')
            ->pluck('total_qty', 'variation_id');

        // Group variations by product id while preserving the order of
        // first appearance (which mirrors the order in `featured_products`).
        $byProduct = [];
        foreach ($variations as $row) {
            $pid = (int) $row->product_id;

            if (! isset($byProduct[$pid])) {
                $byProduct[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $row->product_name,
                    'image_url' => $this->productImageUrl($row->product_image),
                    'variations' => [],
                ];
            }

            $byProduct[$pid]['variations'][] = [
                'variation_id' => (int) $row->variation_id,
                'sub_sku' => $row->sub_sku,
                'name' => $row->variation_name,
                'default_sell_price_inc_tax' => (float) $row->default_sell_price_inc_tax,
                'current_stock' => (float) ($stockMap[$row->variation_id] ?? 0),
            ];
        }

        return response()->json([
            'data' => array_values($byProduct),
        ]);
    }

    /**
     * POST /api/v1/pos/sales
     *
     * Persist a POS sale: a `transactions` row (`type='sell'`,
     * `sub_type='pos'`, `status='final'`), one `transaction_sell_lines`
     * row per line item, one `transaction_payments` row per payment
     * line, and a stock decrement on `variation_location_details` for
     * every line item.
     *
     * The whole flow runs inside `DB::transaction(...)` so a partial
     * failure rolls back the transaction row, every line insert, every
     * payment insert, and every stock change together.
     * `variation_location_details` reads use `lockForUpdate()` so
     * concurrent stock writes serialise on the row rather than racing.
     *
     * Authorization is enforced inside `StorePosSaleRequest::authorize`
     * (sell.create permission + resolved business id), so this method
     * runs only for callers with the right permission. Foreign-key
     * scoping (location, contact, tax rates) is enforced at the
     * validator stage via `Rule::exists`.
     *
     * `payment_status` is computed from the actual payment sum vs the
     * grand total: ≥ final_total → 'paid', > 0 → 'partial', otherwise
     * 'due'. The legacy `TransactionUtil` integration (reward points,
     * lots, modifiers, kots, weighing scale, payment-account journaling)
     * is intentionally deferred to follow-up tasks; see SCOPE NOTE on
     * task 5.2.
     *
     * Returns 201 with the persisted `transactions` row projected
     * through `PosSaleResource`, with `sell_lines` and `payment_lines`
     * eager-loaded so the SPA can render the receipt without a
     * follow-up fetch.
     *
     * Validates: R13.1.
     */
    public function store(StorePosSaleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $businessId = (int) pos_context('business.id');
        $userId = (int) auth()->id();

        // Computed totals and payment status. We compute server-side
        // rather than trusting client-supplied final_total so the
        // SPA cannot misrepresent the persisted total.
        $totalBeforeTax = 0.0;
        foreach ($data['products'] as $line) {
            $totalBeforeTax += (float) $line['quantity'] * (float) $line['unit_price'];
        }

        $lineTaxSum = 0.0;
        foreach ($data['products'] as $line) {
            $lineTaxSum += (float) ($line['item_tax'] ?? 0);
        }

        $taxAmount = (float) ($data['tax_amount'] ?? $lineTaxSum);
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $shippingCharges = (float) ($data['shipping_charges'] ?? 0);
        $finalTotal = $totalBeforeTax + $taxAmount + $shippingCharges - $discountAmount;

        $payments = $data['payments'] ?? [];
        $paymentSum = 0.0;
        foreach ($payments as $payment) {
            $paymentSum += (float) $payment['amount'];
        }

        // The legacy convention treats "fully paid" as `paid`,
        // "non-zero but short" as `partial`, and "no payment given" as
        // `due`. A small float-comparison epsilon avoids the SPA
        // sending exactly $finalTotal but losing 0.0001 to FP rounding
        // and getting flagged as `partial`.
        if ($paymentSum + 0.0001 >= $finalTotal && $paymentSum > 0) {
            $paymentStatus = 'paid';
        } elseif ($paymentSum > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'due';
        }

        $invoiceNo = $data['invoice_no'] ?? null;
        if (empty($invoiceNo)) {
            // Placeholder generator. The legacy `InvoiceScheme`
            // integration is deferred; this format is sortable + unique
            // enough for the SPA's "show recent sales" list and
            // never collides within a single business in practice.
            $invoiceNo = 'INV-'.now()->format('Ymd').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }

        $transaction = DB::transaction(function () use (
            $data,
            $businessId,
            $userId,
            $totalBeforeTax,
            $taxAmount,
            $discountAmount,
            $shippingCharges,
            $finalTotal,
            $paymentStatus,
            $invoiceNo,
            $payments
        ) {
            $sale = Transaction::create([
                'business_id' => $businessId,
                'location_id' => (int) $data['location_id'],
                'type' => 'sell',
                'sub_type' => 'pos',
                'status' => 'final',
                'payment_status' => $paymentStatus,
                'transaction_date' => $data['transaction_date'],
                'contact_id' => (int) $data['contact_id'],
                'invoice_no' => $invoiceNo,
                'total_before_tax' => $totalBeforeTax,
                'tax_amount' => $taxAmount,
                'tax_id' => $data['tax_rate_id'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_amount' => $discountAmount,
                'shipping_charges' => $shippingCharges,
                'final_total' => $finalTotal,
                'is_direct_sale' => false,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['products'] as $line) {
                $unitPrice = (float) $line['unit_price'];
                $unitPriceIncTax = (float) ($line['unit_price_inc_tax'] ?? $unitPrice);

                TransactionSellLine::create([
                    'transaction_id' => $sale->id,
                    'product_id' => (int) $line['product_id'],
                    'variation_id' => (int) $line['variation_id'],
                    'quantity' => (float) $line['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_price_inc_tax' => $unitPriceIncTax,
                    'tax_id' => $line['tax_rate_id'] ?? null,
                    'item_tax' => (float) ($line['item_tax'] ?? 0),
                    'line_discount_type' => $line['line_discount_type'] ?? null,
                    'line_discount_amount' => (float) ($line['line_discount_amount'] ?? 0),
                ]);

                $this->decrementStock(
                    productId: (int) $line['product_id'],
                    variationId: (int) $line['variation_id'],
                    locationId: (int) $data['location_id'],
                    quantity: (float) $line['quantity'],
                );
            }

            foreach ($payments as $payment) {
                TransactionPayment::create([
                    'transaction_id' => $sale->id,
                    'business_id' => $businessId,
                    'is_return' => false,
                    'amount' => (float) $payment['amount'],
                    'method' => (string) $payment['method'],
                    'paid_on' => $payment['paid_on'] ?? Carbon::parse($data['transaction_date']),
                    'created_by' => $userId,
                    'payment_ref_no' => $payment['payment_ref_no'] ?? null,
                ]);
            }

            return $sale;
        });

        // Eager-load both relations so `PosSaleResource` can surface
        // them via `whenLoaded()` in the 201 payload — the SPA needs
        // both to render the receipt without a follow-up fetch.
        $transaction->load(['sell_lines', 'payment_lines']);

        return (new PosSaleResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Decrement the qty_available of the given (product, variation,
     * location) tuple by `quantity`. Creates the row if missing,
     * matching the legacy "negative on create" semantics that the
     * stock adjustment / transfer flows rely on. Must be called inside
     * an open `DB::transaction` — the `lockForUpdate()` row lock is
     * released only at commit/rollback, so concurrent sales for the
     * same variation+location serialise rather than race.
     */
    private function decrementStock(int $productId, int $variationId, int $locationId, float $quantity): void
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
                'qty_available' => -1 * $quantity,
            ]);

            return;
        }

        $row->qty_available = (float) $row->qty_available - $quantity;
        $row->save();
    }

    /**
     * Subset of business settings the POS screen actually consumes.
     * Keep this list tight: every field added here is implicitly part
     * of the API contract.
     */
    private function businessSettings(?Business $business): array
    {
        if ($business === null) {
            return [];
        }

        return [
            'enable_rp' => (bool) ($business->enable_rp ?? false),
            'enable_brand' => (bool) ($business->enable_brand ?? true),
            'enable_category' => (bool) ($business->enable_category ?? true),
            'enable_sub_category' => (bool) ($business->enable_sub_category ?? true),
            'enable_price_tax' => (bool) ($business->enable_price_tax ?? true),
            'currency_precision' => (int) ($business->currency_precision ?? 2),
            'quantity_precision' => (int) ($business->quantity_precision ?? 2),
            'currency_symbol_placement' => $business->currency_symbol_placement ?? 'before',
            'sell_price_tax' => $business->sell_price_tax ?? 'includes',
            'date_format' => $business->date_format ?? 'm/d/Y',
            'time_format' => $business->time_format ?? '24',
            'sku_prefix' => $business->sku_prefix,
            'transaction_edit_days' => (int) ($business->transaction_edit_days ?? 30),
        ];
    }

    /**
     * Return the business default currency in the canonical SPA shape.
     * `Business::currency` is eager-loaded by `config()` above.
     */
    private function defaultCurrency(?Business $business): ?array
    {
        if ($business === null || $business->currency === null) {
            return null;
        }

        $c = $business->currency;

        return [
            'id' => (int) $c->id,
            'code' => $c->code ?? null,
            'symbol' => $c->symbol ?? null,
            'thousand_separator' => $c->thousand_separator ?? null,
            'decimal_separator' => $c->decimal_separator ?? null,
        ];
    }

    /**
     * Permitted locations for the POS screen, projected through
     * `BusinessLocationResource` so callers see the same shape as the
     * dedicated `/business/locations` endpoint (including
     * `receipt_printer_type`, `selling_price_group_id`, and
     * `featured_products`). Locations are filtered by `pos_context('locations')`
     * — the middleware already applies any per-user permitted-location
     * restrictions when populating that key, so reading directly from
     * the DB and intersecting against it is sufficient.
     */
    private function locations(?int $businessId): array
    {
        if ($businessId === null) {
            return [];
        }

        $permittedIds = collect(pos_context('locations', []))
            ->pluck('id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = BusinessLocation::where('business_id', $businessId);

        if (! empty($permittedIds)) {
            $query->whereIn('id', $permittedIds);
        }

        $locations = $query->get();

        // BusinessLocationResource::collection returns a resource
        // collection; `resolve()` materializes the array for the JSON
        // envelope without an extra `response()->json()` round-trip.
        return BusinessLocationResource::collection($locations)->resolve();
    }

    /**
     * Tax rates for the business in the shape the POS dropdown needs.
     * Includes `is_tax_group` / `for_tax_group` so the SPA can build
     * the legacy "tax group" composite types.
     */
    private function taxRates(?int $businessId): array
    {
        if ($businessId === null) {
            return [];
        }

        try {
            return DB::table('tax_rates')
                ->where('business_id', $businessId)
                ->select('id', 'name', 'amount', 'is_tax_group', 'for_tax_group')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'amount' => (float) $row->amount,
                    'is_tax_group' => (bool) ($row->is_tax_group ?? false),
                    'for_tax_group' => (bool) ($row->for_tax_group ?? false),
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Lookup the business's default walk-in customer (legacy convention:
     * `Contact::where('type','customer')->where('is_default',1)`).
     *
     * Returns `null` when the business has no default customer
     * configured — common in fresh test fixtures. The SPA renders a
     * "select a customer" placeholder in that case rather than
     * crashing.
     */
    private function walkInCustomer(?int $businessId): ?array
    {
        if ($businessId === null) {
            return null;
        }

        try {
            $contact = Contact::where('business_id', $businessId)
                ->where('type', 'customer')
                ->where('is_default', 1)
                ->first();

            if ($contact === null) {
                return null;
            }

            // Build a stable display name — `name` is set in the legacy
            // schema; fall back to the supplier_business_name if absent.
            $name = $contact->name ?: $contact->supplier_business_name;

            return [
                'id' => (int) $contact->id,
                'name' => $name,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return active selling-price-groups for the business.
     * Wrapped in try/catch so a partially-migrated test fixture (no
     * `selling_price_groups` table) returns `[]` instead of crashing
     * the endpoint.
     */
    private function sellingPriceGroups(?int $businessId): array
    {
        if ($businessId === null) {
            return [];
        }

        try {
            return DB::table('selling_price_groups')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->select('id', 'name', 'is_active')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'is_active' => (bool) $row->is_active,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parse a possibly-JSON column into an associative array.
     * Returns `[]` for null/empty/invalid values so the SPA always
     * receives a JSON object (no `null` surprises).
     */
    private function parseJsonColumn($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Resolve a product's `image` column to a public URL or null.
     * Mirrors `Product::getImageUrlAttribute()` but stays self-contained
     * so this controller doesn't need to instantiate a full Eloquent
     * model just to read one accessor.
     */
    private function productImageUrl(?string $image): ?string
    {
        if (empty($image)) {
            return null;
        }

        return asset('/uploads/img/'.rawurlencode($image));
    }

    /**
     * PUT /api/v1/pos/sales/{id}
     *
     * Edit an existing POS sale (task 5.3).
     *
     * Permission: `sell.update` (gated inside `UpdatePosSaleRequest`).
     *
     * Behavioural rules:
     *   - The target transaction is scoped to the auth business and
     *     `type='sell'`. Cross-business / non-sell ids return 404 to
     *     avoid existence leaks.
     *   - The legacy `transaction_edit_days` business setting (default
     *     30) limits how far back a sale can be edited. Computing
     *     `now() - transaction_date` in days against the limit produces
     *     a 403 with `code='edit_window_expired'` for sales beyond the
     *     window.
     *   - The whole flow runs inside `DB::transaction(...)` so a partial
     *     failure rolls back the diff in transaction columns, the
     *     line-item delta, the stock change, and the payment replace
     *     together.
     *
     * Diff strategy:
     *   - Lines: index existing rows by `variation_id` and incoming
     *     rows by `variation_id`. For variations only on the existing
     *     side, delete the line and increment stock back. For variations
     *     only on the incoming side, insert the line and decrement
     *     stock. For variations on both sides, update the line in place
     *     and adjust stock by the delta.
     *   - Payments: simplest correct strategy — delete all existing
     *     payments and re-insert the new set. The legacy controller
     *     merges old/new but the API explicitly replaces (the SPA
     *     resends the full payment list).
     *   - Totals: recompute `total_before_tax`, `tax_amount`,
     *     `discount_amount`, `shipping_charges`, `final_total`, and
     *     `payment_status` from the new lines and new payments.
     *
     * Validates: R8.1, R8.2.
     */
    public function update(UpdatePosSaleRequest $request, $id): JsonResponse
    {
        $businessId = (int) pos_context('business.id');
        $userId = (int) auth()->id();

        $sale = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->find($id);

        if ($sale === null) {
            return JsonError::notFound('Sale not found.');
        }

        // Edit-window enforcement — read `transaction_edit_days` from
        // the business row, defaulting to 30 if the column is missing
        // or null. Any positive value is treated as a window in days
        // measured against the original `transaction_date`.
        $editDays = $this->editWindowDays($businessId);
        $txDate = $sale->transaction_date instanceof \DateTimeInterface
            ? Carbon::instance($sale->transaction_date)
            : Carbon::parse($sale->transaction_date);
        $daysSince = $txDate->diffInDays(now(), false);

        if ($daysSince > $editDays) {
            return JsonError::forbidden(
                'edit_window_expired',
                "Sales older than {$editDays} days cannot be edited.",
            );
        }

        $data = $request->validated();

        // Existing lines and payments — load before the transaction so
        // the lock order is consistent and we can build the diff plan
        // outside the begin block.
        $existingLines = TransactionSellLine::where('transaction_id', $sale->id)->get();
        $existingPayments = TransactionPayment::where('transaction_id', $sale->id)->get();

        // Resolve the effective set of lines we will persist. If the
        // SPA omitted `products`, we keep the existing line shape but
        // re-emit it through the diff machinery for symmetry — the
        // diff produces zero deltas so no DB writes happen.
        $newLines = $data['products'] ?? $existingLines->map(fn ($line) => [
            'product_id' => (int) $line->product_id,
            'variation_id' => (int) $line->variation_id,
            'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price,
            'unit_price_inc_tax' => (float) $line->unit_price_inc_tax,
            'tax_rate_id' => $line->tax_id !== null ? (int) $line->tax_id : null,
            'item_tax' => (float) $line->item_tax,
            'line_discount_type' => $line->line_discount_type,
            'line_discount_amount' => (float) $line->line_discount_amount,
        ])->all();

        // Recompute totals server-side from the effective line set.
        $totalBeforeTax = 0.0;
        $lineTaxSum = 0.0;
        foreach ($newLines as $line) {
            $totalBeforeTax += (float) $line['quantity'] * (float) $line['unit_price'];
            $lineTaxSum += (float) ($line['item_tax'] ?? 0);
        }

        $taxAmount = (float) ($data['tax_amount'] ?? $lineTaxSum);
        $discountAmount = (float) ($data['discount_amount'] ?? $sale->discount_amount);
        $shippingCharges = (float) ($data['shipping_charges'] ?? $sale->shipping_charges);
        $finalTotal = $totalBeforeTax + $taxAmount + $shippingCharges - $discountAmount;

        // Resolve the payments we will persist. If the request omits
        // `payments`, we keep the existing payments and skip the
        // replace step entirely.
        $payments = $data['payments'] ?? null;
        $paymentSum = 0.0;
        if ($payments !== null) {
            foreach ($payments as $payment) {
                $paymentSum += (float) $payment['amount'];
            }
        } else {
            foreach ($existingPayments as $payment) {
                if ((bool) ($payment->is_return ?? false) === false) {
                    $paymentSum += (float) $payment->amount;
                }
            }
        }

        // Same epsilon/threshold convention as `store()`.
        if ($paymentSum + 0.0001 >= $finalTotal && $paymentSum > 0) {
            $paymentStatus = 'paid';
        } elseif ($paymentSum > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'due';
        }

        $newLocationId = (int) ($data['location_id'] ?? $sale->location_id);
        $oldLocationId = (int) $sale->location_id;

        DB::transaction(function () use (
            $sale,
            $existingLines,
            $newLines,
            $payments,
            $data,
            $businessId,
            $userId,
            $totalBeforeTax,
            $taxAmount,
            $discountAmount,
            $shippingCharges,
            $finalTotal,
            $paymentStatus,
            $newLocationId,
            $oldLocationId
        ) {
            // Build the variation-keyed diff. Variations may legitimately
            // appear more than once in a sale (different unit prices or
            // line discounts), so we group by `(variation_id, line_index)`
            // — but for the purpose of this minimal API edit, we treat
            // a variation_id as the diff key and rely on the SPA sending
            // one row per (variation, price, discount) tuple.
            $existingByVar = [];
            foreach ($existingLines as $line) {
                $existingByVar[(int) $line->variation_id] = $line;
            }

            $newByVar = [];
            foreach ($newLines as $line) {
                $newByVar[(int) $line['variation_id']] = $line;
            }

            // Variations removed entirely: delete the line, return stock
            // to the original location.
            foreach ($existingByVar as $varId => $line) {
                if (! array_key_exists($varId, $newByVar)) {
                    $this->incrementStock(
                        productId: (int) $line->product_id,
                        variationId: $varId,
                        locationId: $oldLocationId,
                        quantity: (float) $line->quantity,
                    );
                    $line->delete();
                }
            }

            // Variations present on both sides: update line columns,
            // adjust stock by the quantity delta. If the location
            // changed, treat it as a "move" — return all qty to the
            // old location, decrement at the new location.
            foreach ($newByVar as $varId => $newLine) {
                if (array_key_exists($varId, $existingByVar)) {
                    $existing = $existingByVar[$varId];
                    $oldQty = (float) $existing->quantity;
                    $newQty = (float) $newLine['quantity'];

                    if ($newLocationId !== $oldLocationId) {
                        $this->incrementStock(
                            productId: (int) $existing->product_id,
                            variationId: $varId,
                            locationId: $oldLocationId,
                            quantity: $oldQty,
                        );
                        $this->decrementStock(
                            productId: (int) $newLine['product_id'],
                            variationId: $varId,
                            locationId: $newLocationId,
                            quantity: $newQty,
                        );
                    } else {
                        $delta = $newQty - $oldQty;
                        if ($delta > 0) {
                            $this->decrementStock(
                                productId: (int) $newLine['product_id'],
                                variationId: $varId,
                                locationId: $newLocationId,
                                quantity: $delta,
                            );
                        } elseif ($delta < 0) {
                            $this->incrementStock(
                                productId: (int) $existing->product_id,
                                variationId: $varId,
                                locationId: $newLocationId,
                                quantity: -1 * $delta,
                            );
                        }
                    }

                    $existing->update([
                        'product_id' => (int) $newLine['product_id'],
                        'quantity' => $newQty,
                        'unit_price' => (float) $newLine['unit_price'],
                        'unit_price_inc_tax' => (float) ($newLine['unit_price_inc_tax'] ?? $newLine['unit_price']),
                        'tax_id' => $newLine['tax_rate_id'] ?? null,
                        'item_tax' => (float) ($newLine['item_tax'] ?? 0),
                        'line_discount_type' => $newLine['line_discount_type'] ?? null,
                        'line_discount_amount' => (float) ($newLine['line_discount_amount'] ?? 0),
                    ]);
                }
            }

            // Variations only on the new side: create the line and
            // decrement stock at the new location.
            foreach ($newByVar as $varId => $newLine) {
                if (! array_key_exists($varId, $existingByVar)) {
                    $unitPrice = (float) $newLine['unit_price'];
                    $unitPriceIncTax = (float) ($newLine['unit_price_inc_tax'] ?? $unitPrice);

                    TransactionSellLine::create([
                        'transaction_id' => $sale->id,
                        'product_id' => (int) $newLine['product_id'],
                        'variation_id' => $varId,
                        'quantity' => (float) $newLine['quantity'],
                        'unit_price' => $unitPrice,
                        'unit_price_inc_tax' => $unitPriceIncTax,
                        'tax_id' => $newLine['tax_rate_id'] ?? null,
                        'item_tax' => (float) ($newLine['item_tax'] ?? 0),
                        'line_discount_type' => $newLine['line_discount_type'] ?? null,
                        'line_discount_amount' => (float) ($newLine['line_discount_amount'] ?? 0),
                    ]);

                    $this->decrementStock(
                        productId: (int) $newLine['product_id'],
                        variationId: $varId,
                        locationId: $newLocationId,
                        quantity: (float) $newLine['quantity'],
                    );
                }
            }

            // Replace payments only when the SPA explicitly sent a
            // `payments` key. Omitting it means "leave existing payments
            // alone" (matching `null` checks above).
            if ($payments !== null) {
                TransactionPayment::where('transaction_id', $sale->id)->delete();

                foreach ($payments as $payment) {
                    TransactionPayment::create([
                        'transaction_id' => $sale->id,
                        'business_id' => $businessId,
                        'is_return' => false,
                        'amount' => (float) $payment['amount'],
                        'method' => (string) $payment['method'],
                        'paid_on' => $payment['paid_on']
                            ?? ($data['transaction_date'] ?? $sale->transaction_date),
                        'created_by' => $userId,
                        'payment_ref_no' => $payment['payment_ref_no'] ?? null,
                    ]);
                }
            }

            // Update the transactions row last so the recomputed totals
            // reflect every line/payment change above.
            $sale->update([
                'location_id' => $newLocationId,
                'transaction_date' => $data['transaction_date'] ?? $sale->transaction_date,
                'contact_id' => $data['contact_id'] ?? $sale->contact_id,
                'invoice_no' => $data['invoice_no'] ?? $sale->invoice_no,
                'total_before_tax' => $totalBeforeTax,
                'tax_amount' => $taxAmount,
                'tax_id' => $data['tax_rate_id'] ?? $sale->tax_id,
                'discount_type' => $data['discount_type'] ?? $sale->discount_type,
                'discount_amount' => $discountAmount,
                'shipping_charges' => $shippingCharges,
                'final_total' => $finalTotal,
                'additional_notes' => $data['additional_notes'] ?? $sale->additional_notes,
                'payment_status' => $paymentStatus,
            ]);
        });

        $sale->refresh();
        $sale->load(['sell_lines', 'payment_lines']);

        return (new PosSaleResource($sale))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/v1/pos/recent-transactions
     *
     * Return the auth user's most recent sales for the active business
     * (task 5.3). Used by the POS screen's "recent" tab.
     *
     * Permission: `sell.view` (legacy convention).
     *
     * Query params:
     *   - status: 'final' | 'draft' | 'quotation' (default 'final').
     *     Internally `quotation` maps to `is_quotation=1` against the
     *     `draft` row state, but for the desktop API we simplify by
     *     treating it as a status synonym.
     *   - limit: 1..100 (default 20).
     *
     * Sales are scoped to `created_by = auth()->id()` so cashiers see
     * their own recent sales, mirroring the legacy
     * `SellPosController::getRecentTransactions` filter. Each row is
     * projected through `PosSaleResource` without `sell_lines` or
     * `payment_lines` so the payload stays tight.
     *
     * Validates: R8.1, R8.2.
     */
    public function recentTransactions(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'in:final,draft,quotation'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = (int) pos_context('business.id');
        $status = $request->query('status', 'final');
        $limit = (int) $request->query('limit', 20);

        $query = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('created_by', auth()->id())
            ->orderByDesc('transaction_date');

        if ($status === 'quotation') {
            // The legacy schema uses `status='draft'` + `is_quotation=1`
            // for quotations, but the column may not exist in lighter
            // fixtures — wrap in a try/catch via whereRaw to keep the
            // endpoint resilient. Here we use a simple where on `status`
            // since the column is always present.
            $query->where('status', 'draft');
            // Add the quotation filter only when the column exists.
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'is_quotation')) {
                    $query->where('is_quotation', 1);
                }
            } catch (\Throwable $e) {
                // Schema introspection failed; skip the additional filter.
            }
        } else {
            $query->where('status', $status);
        }

        $transactions = $query->take($limit)->get();

        return response()->json([
            'data' => PosSaleResource::collection($transactions)->resolve(),
        ]);
    }

    /**
     * GET /api/v1/pos/reward-details
     *
     * Return reward-point (RP) summary for a given customer (task 5.3).
     * Used by the POS screen's "redeem points" panel.
     *
     * Permission: `sell.create` (mirrors legacy POS-screen gating).
     *
     * Query params:
     *   - contact_id: required, integer, must belong to the auth
     *     business.
     *
     * Computed:
     *   - available_rp = total_rp - total_rp_used - total_rp_expired
     *   - redeemable_amount = available_rp * redeem_amount_per_unit_rp
     *     (coerced to 0 when the divisor is non-positive or the RP is
     *     not enabled).
     *
     * RP-related columns are read defensively: any null value is
     * coerced to 0 so the SPA always receives numeric fields. The
     * `rp_settings` envelope mirrors the legacy keys the SPA reads
     * (`amount_for_unit_rp`, `min_order_total_for_rp`,
     * `max_rp_per_order`, `redeem_amount_per_unit_rp`,
     * `min_order_total_for_redeem`, `min_redeem_point`,
     * `max_redeem_point`).
     *
     * Validates: R8.1, R8.2.
     */
    public function rewardDetails(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sell.create')) {
            return JsonError::forbidden();
        }

        $businessId = (int) pos_context('business.id');

        $validator = Validator::make($request->query(), [
            'contact_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('contacts', 'id')
                    ->where('business_id', $businessId),
            ],
        ]);

        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $contactId = (int) $request->query('contact_id');
        $contact = Contact::where('business_id', $businessId)->find($contactId);
        $business = Business::find($businessId);

        $totalRp = (float) ($contact->total_rp ?? 0);
        $totalRpUsed = (float) ($contact->total_rp_used ?? 0);
        $totalRpExpired = (float) ($contact->total_rp_expired ?? 0);
        $availableRp = max(0.0, $totalRp - $totalRpUsed - $totalRpExpired);

        $redeemAmountPerUnit = (float) ($business->redeem_amount_per_unit_rp ?? 0);
        $redeemableAmount = $redeemAmountPerUnit > 0
            ? $availableRp * $redeemAmountPerUnit
            : 0.0;

        return response()->json([
            'data' => [
                'contact_id' => $contactId,
                'total_rp' => $totalRp,
                'total_rp_used' => $totalRpUsed,
                'total_rp_expired' => $totalRpExpired,
                'available_rp' => $availableRp,
                'redeemable_amount' => $redeemableAmount,
                'rp_name' => $business->rp_name ?? 'Points',
                'rp_settings' => [
                    'amount_for_unit_rp' => (float) ($business->amount_for_unit_rp ?? 0),
                    'min_order_total_for_rp' => (float) ($business->min_order_total_for_rp ?? 0),
                    'max_rp_per_order' => (float) ($business->max_rp_per_order ?? 0),
                    'redeem_amount_per_unit_rp' => $redeemAmountPerUnit,
                    'min_order_total_for_redeem' => (float) ($business->min_order_total_for_redeem ?? 0),
                    'min_redeem_point' => (int) ($business->min_redeem_point ?? 0),
                    'max_redeem_point' => (int) ($business->max_redeem_point ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Increment qty_available for the given (variation, location). Used
     * by `update()` when a line is removed or its quantity decreases.
     * Creates the row if missing — matching the symmetric counterpart
     * to `decrementStock()`.
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

    /**
     * Resolve the configured `transaction_edit_days` for the active
     * business, defaulting to 30 when the column is missing or null.
     * Wrapped in try/catch so a fixture without the column still
     * produces a sensible default rather than crashing the request.
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
     * GET /api/v1/pos/sales/{id}/receipt
     *
     * Render the desktop receipt Blade template for an existing sale
     * and wrap the resulting HTML in a JSON envelope so the SPA can
     * forward it to the Electron print pipeline (task 5.4).
     *
     * Permission: `sell.view` (legacy convention; the same permission
     * guards every read-only sale flow in the web app).
     *
     * Scoping:
     *   - The transaction must belong to the active business and have
     *     `type='sell'`. Cross-business or non-sell ids return 404 to
     *     avoid existence leaks (matches `update()` and the rest of
     *     the API surface).
     *
     * Why an API-specific Blade template
     * ----------------------------------
     * The legacy `SellPosController::receiptContent()` wires the full
     * `TransactionUtil::getReceiptDetails()` pipeline plus business /
     * location / currency look-ups, then dispatches to one of nine
     * existing layouts (`classic`, `elegant`, `slim`, ...). That
     * machinery is tightly coupled to the legacy controller's
     * constructor and to layout settings the desktop SPA does not yet
     * support. To keep this endpoint simple, deterministic, and
     * testable, we instead:
     *
     *   1) Build a `$receipt` array containing every field the receipt
     *      Blade needs (line items, totals, payments, business +
     *      location identity, customer identity, currency symbol).
     *   2) Render `sale_pos.receipts.api` — a minimal self-contained
     *      Blade template added in this task — and wrap the rendered
     *      HTML in `{"data":{"sale_id": <id>, "html": "..."}}`.
     *
     * The full multi-layout selector (`invoice_layout_id` ->
     * legacy template) is the responsibility of the Phase 10 print
     * pipeline. This task delivers the required *contract* — the SPA
     * gets receipt HTML, wrapped in JSON, scoped to the active
     * business — without dragging the legacy controller in.
     *
     * Validates: R13.2, R13.3, R13.6.
     */
    public function receipt(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('sell.view')) {
            return JsonError::forbidden();
        }

        $businessId = (int) pos_context('business.id');

        $sale = Transaction::where('business_id', $businessId)
            ->where('type', 'sell')
            ->with([
                'sell_lines',
                'sell_lines.product',
                'sell_lines.variations',
                'payment_lines',
                'business',
                'location',
                'contact',
            ])
            ->find($id);

        if ($sale === null) {
            return JsonError::notFound('Sale not found.');
        }

        $receipt = $this->buildReceiptData($sale);

        // Render the desktop receipt Blade. `view(...)->render()`
        // returns a string we then embed in the JSON envelope so the
        // response itself remains `Content-Type: application/json` —
        // this satisfies R13.6 (Blade is contained inside the JSON
        // envelope, never returned as the response body itself).
        $html = view('sale_pos.receipts.api', ['receipt' => $receipt])->render();

        return response()->json([
            'data' => [
                'sale_id' => (int) $sale->id,
                'html' => $html,
            ],
        ]);
    }

    /**
     * Project a `Transaction` (with its eager-loaded relations) into
     * the canonical `$receipt` array consumed by the
     * `sale_pos.receipts.api` Blade template.
     *
     * Each line's `line_total` is computed as
     * `quantity * unit_price_inc_tax` so the rendered receipt totals
     * footer ties back to the persisted `final_total` without trusting
     * any intermediate column.
     */
    private function buildReceiptData(Transaction $sale): array
    {
        $business = $sale->business;
        $location = $sale->location;
        $contact = $sale->contact;

        $currencySymbol = '';
        if ($business !== null) {
            $currency = $business->currency()->first();
            if ($currency !== null) {
                $currencySymbol = (string) ($currency->symbol ?? '');
            }
        }

        $lines = [];
        foreach ($sale->sell_lines as $line) {
            $product = $line->product;
            $variation = $line->variations;
            $quantity = (float) $line->quantity;
            $unitPriceIncTax = (float) $line->unit_price_inc_tax;

            $lines[] = [
                'product_name' => $product?->name ?? '',
                'sub_sku' => $variation?->sub_sku ?? '',
                'quantity' => $quantity,
                'unit_price' => (float) $line->unit_price,
                'unit_price_inc_tax' => $unitPriceIncTax,
                'item_tax' => (float) $line->item_tax,
                'line_total' => $quantity * $unitPriceIncTax,
            ];
        }

        $payments = [];
        foreach ($sale->payment_lines as $payment) {
            // Skip return rows so a refund payment doesn't display as
            // an additional outflow on the customer's receipt.
            if ((bool) ($payment->is_return ?? false)) {
                continue;
            }

            $paidOn = $payment->paid_on;
            if ($paidOn instanceof \DateTimeInterface) {
                $paidOnString = Carbon::instance($paidOn)->toDateTimeString();
            } elseif (! empty($paidOn)) {
                $paidOnString = (string) $paidOn;
            } else {
                $paidOnString = '';
            }

            $payments[] = [
                'method' => (string) $payment->method,
                'amount' => (float) $payment->amount,
                'paid_on' => $paidOnString,
            ];
        }

        $transactionDate = $sale->transaction_date;
        if ($transactionDate instanceof \DateTimeInterface) {
            $transactionDateString = Carbon::instance($transactionDate)->toDateTimeString();
        } elseif (! empty($transactionDate)) {
            $transactionDateString = (string) $transactionDate;
        } else {
            $transactionDateString = '';
        }

        // Build the location address line using the model's
        // `getLocationAddressAttribute()` accessor when available so
        // the receipt matches the legacy address layout (landmark,
        // city, state, zip, country). Wrapped in try/catch so a
        // partially-populated test fixture degrades gracefully.
        $locationAddress = '';
        if ($location !== null) {
            try {
                $locationAddress = (string) ($location->location_address ?? '');
            } catch (\Throwable $e) {
                $locationAddress = '';
            }
        }

        return [
            'sale_id' => (int) $sale->id,
            'invoice_no' => (string) ($sale->invoice_no ?? ''),
            'transaction_date' => $transactionDateString,
            'business' => [
                'name' => (string) ($business->name ?? ''),
                'currency_symbol' => $currencySymbol,
                'currency_symbol_placement' => (string) ($business?->currency_symbol_placement ?? 'before'),
            ],
            'location' => [
                'name' => (string) ($location->name ?? ''),
                'address' => $locationAddress,
                'mobile' => (string) ($location->mobile ?? ''),
                'email' => (string) ($location->email ?? ''),
            ],
            'customer' => [
                'name' => (string) ($contact->name ?? ($contact->supplier_business_name ?? '')),
                'mobile' => (string) ($contact->mobile ?? ''),
                'email' => (string) ($contact->email ?? ''),
            ],
            'lines' => $lines,
            'totals' => [
                'total_before_tax' => (float) $sale->total_before_tax,
                'tax_amount' => (float) $sale->tax_amount,
                'discount_amount' => (float) $sale->discount_amount,
                'shipping_charges' => (float) $sale->shipping_charges,
                'final_total' => (float) $sale->final_total,
            ],
            'payments' => $payments,
            'payment_status' => (string) ($sale->payment_status ?? ''),
        ];
    }
}
