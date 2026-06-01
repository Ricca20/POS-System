<?php

namespace App\Http\Controllers\Api;

use App\Brands;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Http\Responses\JsonError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read endpoints for `App\Brands` consumed by the desktop SPA.
 *
 *   GET  /api/v1/brands         -> index()
 *   GET  /api/v1/brands/{id}    -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. Cross-business
 * reads return 404 rather than 403 to avoid leaking the existence of
 * other businesses' brands.
 *
 * The Eloquent class is `App\Brands` (plural) backed by the singular
 * `brands` table — keep the import in sync with that legacy naming.
 *
 * No permission gating: the SPA's product editor and POS need the brand
 * dropdown for every form, and the legacy `BrandController::index`
 * grants read access to anyone with `brand.view` OR `brand.create`.
 * `auth:sanctum` is sufficient for taxonomy-style reads.
 *
 * Validates: R8.1, R8.2.
 */
class BrandApiController extends Controller
{
    /**
     * GET /api/v1/brands
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $brands = Brands::where('business_id', $businessId)
            ->orderBy('name', 'asc')
            ->get();

        return BrandResource::collection($brands);
    }

    /**
     * GET /api/v1/brands/{id}
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $brand = Brands::where('business_id', $businessId)->find($id);

        if ($brand === null) {
            return JsonError::notFound('Brand not found.');
        }

        return (new BrandResource($brand))
            ->response()
            ->setStatusCode(200);
    }
}
