<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VariationTemplateResource;
use App\Http\Responses\JsonError;
use App\VariationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read endpoints for `App\VariationTemplate` consumed by the desktop SPA.
 *
 *   GET  /api/v1/variation-templates         -> index()
 *   GET  /api/v1/variation-templates/{id}    -> show()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. Cross-business
 * reads return 404.
 *
 * Both methods eager-load the `values` relation so the SPA receives the
 * full template + values payload in a single round trip — the resource
 * embeds them under `variation_value_templates`. Without eager loading
 * the resource's `whenLoaded` projection would silently omit the list,
 * so the convention is established here at the controller level.
 *
 * No permission gating — see the controller-class doc on
 * `CategoryApiController` for the rationale.
 *
 * Validates: R8.1, R8.2.
 */
class VariationTemplateApiController extends Controller
{
    /**
     * GET /api/v1/variation-templates
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $businessId = pos_context('business.id');

        $templates = VariationTemplate::where('business_id', $businessId)
            ->with('values')
            ->orderBy('name', 'asc')
            ->get();

        return VariationTemplateResource::collection($templates);
    }

    /**
     * GET /api/v1/variation-templates/{id}
     */
    public function show($id): JsonResponse
    {
        $businessId = pos_context('business.id');

        $template = VariationTemplate::where('business_id', $businessId)
            ->with('values')
            ->find($id);

        if ($template === null) {
            return JsonError::notFound('Variation template not found.');
        }

        return (new VariationTemplateResource($template))
            ->response()
            ->setStatusCode(200);
    }
}
