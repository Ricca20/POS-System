<?php

namespace App\Http\Controllers\Api;

use App\Business;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateBusinessSettingsRequest;
use App\Http\Resources\BusinessResource;
use App\Http\Responses\JsonError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business settings endpoints for the desktop SPA.
 *
 *   GET  /api/v1/business/settings   -> show()
 *   PUT  /api/v1/business/settings   -> update()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` so the controller
 * may resolve the active business via `pos_context()` (R8.5) and never
 * touches `session()` (R8.4).
 *
 * Mapped from the legacy `App\Http\Controllers\BusinessController`
 * (`getBusinessSettings` / `postBusinessSettings`) but stripped of every
 * Blade/session/file-upload concern. Logo upload, email/SMS settings,
 * keyboard shortcuts, custom labels, and the 70+ rarely-used legacy
 * fields are intentionally out of scope for this leaf task — they will
 * land in dedicated endpoints when the SPA needs them.
 *
 * Validates: R8.1, R8.2, R8.4.
 */
class BusinessApiController extends Controller
{
    /**
     * Boolean settings flags. The legacy column type is `tinyint(1)`,
     * so we cast the request input to a real bool to avoid storing the
     * string `"1"` / `"true"` and breaking existing JS that does `=== 1`.
     */
    private const BOOLEAN_FIELDS = [
        'enable_rp',
        'enable_brand',
        'enable_category',
        'enable_sub_category',
        'enable_price_tax',
    ];

    /**
     * GET /api/v1/business/settings
     *
     * Returns the active business profile + embedded currency. Read
     * access requires only authentication; the SPA shell relies on this
     * endpoint for every settings render so further permission gating
     * would block ordinary cashiers from seeing their own business
     * profile (mirrors the implicit "read is open" stance of the legacy
     * web flow).
     */
    public function show(Request $request): JsonResponse
    {
        $businessId = pos_context('business.id');

        if ($businessId === null) {
            return JsonError::notFound('No business is associated with the authenticated user.');
        }

        $business = Business::with('currency')->find($businessId);

        if ($business === null) {
            return JsonError::notFound('Business not found.');
        }

        return (new BusinessResource($business))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * PUT /api/v1/business/settings
     *
     * Validation, authorization, and permission gating already happened
     * inside `UpdateBusinessSettingsRequest::authorize` and `rules`, so
     * this method is a thin "find -> fill -> save -> return" wrapper.
     *
     * Only fields explicitly listed in the form request rules are
     * applied; any extra field in the payload is silently ignored
     * (Laravel's `validated()` only returns rule-named keys), which
     * protects the legacy `business` row from drive-by mass assignment.
     */
    public function update(UpdateBusinessSettingsRequest $request): JsonResponse
    {
        $businessId = pos_context('business.id');

        // Defensive: `authorize()` already rejected this case, but keep
        // the 404 guard so a misconfigured pipeline returns a clean
        // envelope instead of a NotFoundException stack trace.
        if ($businessId === null) {
            return JsonError::notFound('No business is associated with the authenticated user.');
        }

        $business = Business::with('currency')->find($businessId);

        if ($business === null) {
            return JsonError::notFound('Business not found.');
        }

        $validated = $request->validated();

        // Cast booleans explicitly so the on-disk column stores the
        // numeric form the legacy code paths expect.
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = (bool) $validated[$field];
            }
        }

        $business->fill($validated);
        $business->save();

        // Reload the relation so the response reflects the new currency
        // when `currency_id` was part of the update.
        $business->load('currency');

        return (new BusinessResource($business))
            ->response()
            ->setStatusCode(200);
    }
}
