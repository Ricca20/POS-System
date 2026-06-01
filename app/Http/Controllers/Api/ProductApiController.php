<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductRequest;
use App\Http\Requests\Api\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\JsonError;
use App\Product;
use App\Variation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Product CRUD endpoints for the desktop SPA (task 4.1).
 *
 *   GET    /api/v1/products            -> index()    (paginated, filterable)
 *   GET    /api/v1/products/{id}       -> show()
 *   POST   /api/v1/products            -> store()
 *   PUT    /api/v1/products/{id}       -> update()
 *   DELETE /api/v1/products/{id}       -> destroy()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so this controller
 * resolves the active business id via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * Every query is scoped to `pos_context('business.id')` so a token issued
 * for business A cannot enumerate, read, modify, or delete products that
 * belong to business B. Cross-business reads/deletes return 404 (rather
 * than 403) so the existence of products in other businesses is not
 * leaked — this matches the convention established in
 * `BusinessLocationApiController`.
 *
 * Permission gating mirrors the legacy `ProductController` ability checks
 * (`product.view`, `product.create`, `product.update`, `product.delete`).
 * For `index`/`show`/`destroy` the check happens inline at the top of the
 * method; for `store`/`update` it lives in the form request's
 * `authorize()` so validation rules can short-circuit on a 403 before
 * touching the rules pipeline.
 *
 * Scope deferred to follow-up tasks (4.2 and beyond):
 *   - Variations, image uploads, modifier sets, kits/combos.
 *   - Location attachments (`product_locations`), opening stock,
 *     selling-price groups, expiry/lot batches.
 *   - Mass operations (mass deactivate, bulk edit).
 *   - The `is_inactive` activate/deactivate flow (separate endpoint).
 *
 * Validates: R8.1, R8.2.
 */
class ProductApiController extends Controller
{
    /**
     * Maximum page size accepted via the `?per_page=` query param.
     *
     * The 100 ceiling matches the convention used elsewhere in the API
     * layer: large enough that the SPA can pull a full page of results
     * for power users, small enough that a single response body stays
     * comfortably under any reverse proxy buffer limits.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default page size when the caller does not supply `?per_page=`.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * GET /api/v1/products
     *
     * Returns a paginated, filtered list of products belonging to the
     * authenticated user's business. Eager-loads `category`, `unit`, and
     * `brand` so the SPA can render a list row without follow-up calls.
     *
     * Query params:
     *   q             — name/sku LIKE (case-insensitive on MySQL).
     *   category_id   — exact match on `category_id`.
     *   brand_id      — exact match on `brand_id`.
     *   enable_stock  — '1' or '0' filter on the stock flag.
     *   per_page      — page size override (capped at MAX_PER_PAGE).
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! auth()->user()->can('product.view')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $query = Product::with(['category', 'unit', 'brand'])
            ->where('business_id', $businessId);

        // Free-text search across name and sku. Use a parameterized
        // LIKE so user input is never interpolated into the SQL.
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($sub) use ($like) {
                $sub->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->query('brand_id'));
        }

        if ($request->has('enable_stock') && $request->query('enable_stock') !== '') {
            // Accept the canonical string forms '1' / '0' the SPA sends
            // for boolean filters; coerce to 0/1 for the LIKE-free WHERE.
            $flag = filter_var($request->query('enable_stock'), FILTER_VALIDATE_BOOLEAN);
            $query->where('enable_stock', $flag ? 1 : 0);
        }

        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $paginator = $query->orderBy('id')->paginate($perPage);

        // ProductResource::collection on a LengthAwarePaginator emits
        // `data`, `links`, and `meta` automatically.
        return ProductResource::collection($paginator);
    }

    /**
     * GET /api/v1/products/{id}
     *
     * Looks up a product scoped to the active business so a probe for
     * an id owned by a different business returns 404 rather than 403.
     */
    public function show(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('product.view')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $product = Product::with(['category', 'unit', 'brand'])
            ->where('business_id', $businessId)
            ->find($id);

        if ($product === null) {
            return JsonError::notFound('Product not found.');
        }

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * POST /api/v1/products
     *
     * Authorization is performed inside `StoreProductRequest::authorize`,
     * so this method runs only for callers with `product.create`.
     *
     * `business_id` and `created_by` are injected here so the SPA cannot
     * forge them via the request body — the form request rules deliberately
     * do not list those keys, and `Product::$guarded` is `['id']` only,
     * making explicit injection the safest path.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['business_id'] = pos_context('business.id');
        $data['created_by'] = auth()->id();

        $product = Product::create($data);

        $product->load(['category', 'unit', 'brand']);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT /api/v1/products/{id}
     *
     * Authorization lives in `UpdateProductRequest::authorize`. Cross-
     * business updates return 404 to avoid existence leaks.
     */
    public function update(UpdateProductRequest $request, $id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $product = Product::where('business_id', $businessId)->find($id);

        if ($product === null) {
            return JsonError::notFound('Product not found.');
        }

        $product->fill($request->validated());
        $product->save();

        $product->load(['category', 'unit', 'brand']);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * DELETE /api/v1/products/{id}
     *
     * The `Product` model does NOT use the `SoftDeletes` trait, so this
     * is a hard delete. Any cascade (variations, purchase lines, etc.)
     * is the responsibility of the schema's foreign keys / a future task
     * that introduces soft deletes if/when needed.
     *
     * Returns 204 No Content on success per HTTP conventions for DELETE.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (! auth()->user()->can('product.delete')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $product = Product::where('business_id', $businessId)->find($id);

        if ($product === null) {
            return JsonError::notFound('Product not found.');
        }

        $product->delete();

        return response()->json(null, 204);
    }

    /**
     * GET /api/v1/products/search
     *
     * POS-screen typeahead lookup. Returns up to 30 variations whose
     * parent product matches the free-text query (`q`) on `name`,
     * `sku`, or the variation's `sub_sku`. When `location_id` is
     * supplied, results are restricted to variations that have a
     * `variation_location_details` row for that location — this matches
     * the legacy behaviour where products are explicitly enabled per
     * location and the POS only offers what's stocked at the active
     * register.
     *
     * Validates: R8.1, R8.2, R8.5 (business-scoping via `pos_context`).
     *
     * Field selection rationale: the SPA's typeahead row needs just
     * enough to render a result and decide whether to load the full
     * cart row on click. Returning a tight, hand-rolled payload keeps
     * the response under a few KB even with the 30-row cap.
     *
     * Stock aggregation: `current_stock` sums `qty_available` across
     * the `variation_location_details` rows. When `location_id` is
     * supplied the sum is filtered to that location only; when it is
     * not, all locations are summed. Products with `enable_stock = 0`
     * always report `0.0000` here — the SPA uses `enable_stock` to
     * decide whether `current_stock` is meaningful for the user.
     */
    public function search(Request $request): JsonResponse
    {
        if (! auth()->user()->can('product.view')) {
            return JsonError::forbidden();
        }

        $businessId = pos_context('business.id');

        $q = trim((string) $request->query('q', ''));
        $locationId = $request->filled('location_id')
            ? (int) $request->query('location_id')
            : null;

        // Build the variations-joined-on-products query. Selecting only
        // the columns we project keeps the row footprint small and
        // avoids pulling Eloquent attributes (e.g. `image_url` accessor)
        // we never use here.
        $query = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $businessId)
            ->whereNull('variations.deleted_at')
            ->select([
                'variations.id as variation_id',
                'variations.product_id',
                'products.name as product_name',
                'variations.sub_sku',
                'variations.default_sell_price',
                'variations.default_sell_price_inc_tax',
                'products.enable_stock',
            ]);

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($sub) use ($like) {
                $sub->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('variations.sub_sku', 'like', $like);
            });
        }

        if ($locationId !== null) {
            // EXISTS subquery instead of a JOIN so the variation row is
            // returned at most once even if multiple `variation_location_details`
            // rows exist for the same (variation, location) pair.
            $query->whereExists(function ($sub) use ($locationId) {
                $sub->select(DB::raw(1))
                    ->from('variation_location_details')
                    ->whereColumn('variation_location_details.variation_id', 'variations.id')
                    ->where('variation_location_details.location_id', $locationId);
            });
        }

        $rows = $query->orderBy('variations.id')->limit(30)->get();

        if ($rows->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // Compute current_stock for each result in a single grouped
        // query, then attach. Done after the row fetch so the limit-30
        // cap drives the cost rather than the join shape.
        $variationIds = $rows->pluck('variation_id')->all();
        $stockQuery = DB::table('variation_location_details')
            ->whereIn('variation_id', $variationIds)
            ->select('variation_id', DB::raw('SUM(qty_available) as total_qty'))
            ->groupBy('variation_id');

        if ($locationId !== null) {
            $stockQuery->where('location_id', $locationId);
        }

        $stockMap = $stockQuery->pluck('total_qty', 'variation_id');

        $data = $rows->map(function ($row) use ($stockMap) {
            $enableStock = (bool) $row->enable_stock;

            return [
                'variation_id' => (int) $row->variation_id,
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'sub_sku' => $row->sub_sku,
                'default_sell_price' => (float) $row->default_sell_price,
                'default_sell_price_inc_tax' => (float) $row->default_sell_price_inc_tax,
                'enable_stock' => $enableStock,
                'current_stock' => $enableStock
                    ? (float) ($stockMap[$row->variation_id] ?? 0)
                    : 0.0,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/products/{variation_id}/pos-row
     *
     * Returns the structured cart-row payload the POS SPA renders when
     * a cashier picks a variation from the typeahead. The legacy
     * `SellPosController::getProductRow` returns Blade HTML; this
     * endpoint returns JSON so the SPA owns rendering.
     *
     * `{variation_id}` matches the legacy URL — the path parameter is
     * the `variations.id`, NOT the parent `products.id`.
     *
     * Validation:
     *   - `location_id` is required (stock lookup is location-scoped).
     *   - `quantity` is optional, defaults to 1, must be > 0.
     *
     * Stock check semantics: legacy POS warns rather than blocks an
     * over-sell. The same convention is followed here — `in_stock`
     * is `false` and `available_quantity` reports the current value
     * when `enable_stock = true` and `quantity > current_stock`. The
     * SPA decides whether to allow the over-sell based on its own UX
     * rules. When `enable_stock = false`, `in_stock` is always `true`.
     *
     * Validates: R8.1, R8.2, R8.5.
     */
    public function posRow(Request $request, $variationId): JsonResponse
    {
        if (! auth()->user()->can('product.view')) {
            return JsonError::forbidden();
        }

        $validator = Validator::make($request->query(), [
            'location_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        if ($validator->fails()) {
            return JsonError::validationFailed($validator->errors()->toArray());
        }

        $businessId = pos_context('business.id');
        $locationId = (int) $request->query('location_id');
        $quantity = (float) $request->query('quantity', 1);

        // Eager-load the parent product (full_name accessor reads it)
        // and only the location-scoped variation_location_details row
        // (stock lookup). `group_prices` is loaded so future selling-
        // price-group support has the data on hand without extending
        // this method.
        $variation = Variation::with([
            'product',
            'variation_location_details' => function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            },
            'group_prices',
            'product_variation',
        ])->find($variationId);

        if ($variation === null) {
            return JsonError::notFound('Variation not found.');
        }

        // Cross-business isolation: any product not owned by the auth
        // business is reported as 404 rather than 403, matching the
        // convention established for the rest of the product CRUD
        // surface (see class docblock).
        if ($variation->product === null || (int) $variation->product->business_id !== (int) $businessId) {
            return JsonError::notFound('Variation not found.');
        }

        $product = $variation->product;
        $enableStock = (bool) $product->enable_stock;

        $vld = $variation->variation_location_details->first();
        $currentStock = $vld === null ? 0.0 : (float) $vld->qty_available;

        // Over-sell detection. Stock-disabled products always pass.
        $inStock = ! $enableStock || $currentStock >= $quantity;

        $payload = [
            'variation_id' => (int) $variation->id,
            'product_id' => (int) $variation->product_id,
            'product_name' => $product->name,
            'sub_sku' => $variation->sub_sku,
            'full_name' => $variation->full_name,
            'unit_id' => $product->unit_id,
            'tax_rate_id' => $product->tax,
            'tax_type' => $product->tax_type,
            'default_sell_price' => (float) $variation->default_sell_price,
            'default_sell_price_inc_tax' => (float) $variation->default_sell_price_inc_tax,
            'default_purchase_price' => (float) $variation->default_purchase_price,
            'current_stock' => $currentStock,
            'enable_stock' => $enableStock,
            'quantity' => $quantity,
            'in_stock' => $inStock,
        ];

        // Only surface `available_quantity` on the over-sell branch so
        // the SPA can detect the warning condition with a single key
        // check. Suppressing it on the happy path keeps the payload
        // shape predictable for callers that only handle the warning.
        if (! $inStock) {
            $payload['available_quantity'] = $currentStock;
        }

        return response()->json(['data' => $payload]);
    }
}
