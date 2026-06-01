<?php

namespace App\Http\Controllers\Api;

use App\Category;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\JsonError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read endpoints for `App\Category` consumed by the desktop SPA.
 *
 *   GET  /api/v1/categories          -> index()
 *   GET  /api/v1/categories/{id}     -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so this controller
 * resolves the active business id via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * The legacy `TaxonomyController::index` filters by the request's
 * `type` query param (either `product` or `tax`). We carry the same
 * convention forward: `?type=product` (default) returns product
 * categories, `?type=tax` returns tax categories. Anything else falls
 * back to the default to avoid surprising the SPA with 4xx noise on
 * misspelled input.
 *
 * Permission gating is intentionally NOT applied to these read
 * endpoints. Categories are consumed as dropdown data by every product
 * and POS form, and the legacy `TaxonomyController` already grants read
 * access to anyone with `category.view` OR `category.create`. The
 * `auth:sanctum` middleware ensures the caller is logged in, which is
 * sufficient for taxonomy reads in the desktop SPA.
 *
 * Validates: R8.1, R8.2.
 */
class CategoryApiController extends Controller
{
    /**
     * GET /api/v1/categories
     *
     * Returns every category belonging to the authenticated user's
     * business, optionally filtered by `?type=product|tax`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $type = $request->query('type', 'product');
        // Constrain the type filter to the two values the legacy schema
        // uses. Anything else silently falls back to 'product' so the
        // SPA gets a sensible default rather than a 4xx for typos.
        if (! in_array($type, ['product', 'tax'], true)) {
            $type = 'product';
        }

        $categories = Category::where('business_id', $businessId)
            ->where('category_type', $type)
            ->orderBy('name', 'asc')
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * GET /api/v1/categories/{id}
     *
     * Look up a category by id, scoped to the active business so a
     * probe for an id owned by a different business returns 404 rather
     * than 403 (avoids leaking existence of other businesses' data).
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $category = Category::where('business_id', $businessId)->find($id);

        if ($category === null) {
            return JsonError::notFound('Category not found.');
        }

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(200);
    }
}
