<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxRateResource;
use App\Http\Responses\JsonError;
use App\TaxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read endpoints for `App\TaxRate` consumed by the desktop SPA.
 *
 *   GET  /api/v1/tax-rates         -> index()
 *   GET  /api/v1/tax-rates/{id}    -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. Cross-business
 * reads return 404.
 *
 * Index does NOT apply the legacy `ExcludeForTaxGroup` scope: the SPA
 * receives every row (group + atomic) plus the `is_tax_group` /
 * `for_tax_group` flags, and decides which subset to display in any
 * particular dropdown. This trade keeps the API uniform; the legacy
 * web UI's filtered views can be replicated client-side.
 *
 * No permission gating — see the controller-class doc on
 * `CategoryApiController` for the rationale.
 *
 * Validates: R8.1, R8.2.
 */
class TaxRateApiController extends Controller
{
    /**
     * GET /api/v1/tax-rates
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $taxRates = TaxRate::where('business_id', $businessId)
            ->orderBy('name', 'asc')
            ->get();

        return TaxRateResource::collection($taxRates);
    }

    /**
     * GET /api/v1/tax-rates/{id}
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $taxRate = TaxRate::where('business_id', $businessId)->find($id);

        if ($taxRate === null) {
            return JsonError::notFound('Tax rate not found.');
        }

        return (new TaxRateResource($taxRate))
            ->response()
            ->setStatusCode(200);
    }
}
