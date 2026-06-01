<?php

namespace App\Http\Controllers\Api;

use App\BusinessLocation;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessLocationResource;
use App\Http\Responses\JsonError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Business location read endpoints for the desktop SPA.
 *
 *   GET  /api/v1/business/locations        -> index()
 *   GET  /api/v1/business/locations/{id}   -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so this controller
 * resolves the active business id via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * Both methods scope every query to the current business id so a token
 * issued for business A cannot enumerate or read locations belonging to
 * business B. Cross-business reads return 404 (rather than 403) so the
 * existence of locations in other businesses is not leaked.
 *
 * Validates: R8.1, R8.2.
 */
class BusinessLocationApiController extends Controller
{
    /**
     * GET /api/v1/business/locations
     *
     * Return every location belonging to the authenticated user's
     * business, wrapped in the standard JSON resource collection.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $locations = BusinessLocation::where('business_id', $businessId)->get();

        return BusinessLocationResource::collection($locations);
    }

    /**
     * GET /api/v1/business/locations/{id}
     *
     * Look up a location by id, scoped to the active business so that
     * a probe for an id owned by a different business returns 404.
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $location = BusinessLocation::where('business_id', $businessId)->find($id);

        if ($location === null) {
            return JsonError::notFound('Business location not found.');
        }

        return (new BusinessLocationResource($location))
            ->response()
            ->setStatusCode(200);
    }
}
