<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use App\Http\Responses\JsonError;
use App\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read endpoints for `App\Unit` consumed by the desktop SPA.
 *
 *   GET  /api/v1/units         -> index()
 *   GET  /api/v1/units/{id}    -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. Cross-business
 * reads return 404.
 *
 * Index returns BOTH base units and sub-units in a flat list. The SPA
 * decides whether to render the hierarchy via `base_unit_id`. Sorting
 * by `actual_name` keeps the dropdown alphabetical regardless of
 * insertion order.
 *
 * No permission gating — see the controller-class doc on
 * `CategoryApiController` for the rationale (taxonomy reads are
 * dropdown data; `auth:sanctum` is sufficient).
 *
 * Validates: R8.1, R8.2.
 */
class UnitApiController extends Controller
{
    /**
     * GET /api/v1/units
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $units = Unit::where('business_id', $businessId)
            ->orderBy('actual_name', 'asc')
            ->get();

        return UnitResource::collection($units);
    }

    /**
     * GET /api/v1/units/{id}
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $unit = Unit::where('business_id', $businessId)->find($id);

        if ($unit === null) {
            return JsonError::notFound('Unit not found.');
        }

        return (new UnitResource($unit))
            ->response()
            ->setStatusCode(200);
    }
}
