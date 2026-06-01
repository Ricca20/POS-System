<?php

namespace App\Http\Middleware;

use App\Business;
use App\BusinessLocation;
use App\Utils\BusinessUtil;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the per-request "POS context" the legacy `SetSessionData` web
 * middleware (`app/Http/Middleware/SetSessionData.php`) used to push into
 * the PHP session, but writes it instead into a request-scoped container
 * binding under the `pos.context` key.
 *
 * The container holds the array directly via `app()->instance()`, which is
 * scoped to the current request lifecycle. API controllers and the
 * `pos_context()` helper (see `app/Http/api_helpers.php`) read from the
 * same key, so business/user/permission context is reachable without the
 * `session()` helper — satisfying R8.4 and R8.5 for `/api/v1/*`.
 *
 * The middleware is intentionally defensive: locations and the financial
 * year query both touch tables (`business_locations`) and configuration
 * (`fy_start_month`) that are not present in lightweight test fixtures.
 * Failures of those auxiliary lookups must NOT crash the API request, so
 * each one is wrapped in `try { ... } catch (\Throwable) { ... }` and
 * yields a benign default (`[]` / `null`).
 *
 * Validates: R8.4, R8.5.
 */
class SetSessionDataApi
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defence in depth: this middleware is mounted behind
        // `auth:sanctum` (see `routes/api.php` and the route group in
        // `ModuleApiRouteProvider`), so unauthenticated traffic should
        // already have been rejected. If, for any reason, the user is
        // unresolved at this point, skip context assembly so the next
        // pipeline stage can decide what to do.
        $user = auth('sanctum')->user();

        if ($user === null) {
            return $next($request);
        }

        $context = $this->buildContext($user);

        // `instance()` (not `singleton()`) stores the value directly so
        // subsequent `app('pos.context')` calls return the exact same
        // array. The container is per-request in a typical Laravel HTTP
        // lifecycle, so the value is automatically discarded when the
        // request ends.
        app()->instance('pos.context', $context);

        return $next($request);
    }

    /**
     * Build the context array mirroring the legacy `SetSessionData`
     * payload but adding `permissions` (Spatie) and tolerating missing
     * tables.
     */
    private function buildContext($user): array
    {
        $business = Business::find($user->business_id);
        $currency = $business?->currency;

        $context = [
            'user' => [
                'id' => $user->id,
                'surname' => $user->surname,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'username' => $user->username,
                'business_id' => $user->business_id,
                'language' => $user->language,
                'user_type' => $user->user_type,
            ],
            'business' => $business === null ? null : [
                'id' => $business->id,
                'name' => $business->name,
                'currency_id' => $business->currency_id,
                'is_active' => (bool) $business->is_active,
                'time_zone' => $business->time_zone ?? null,
            ],
            'currency' => $currency === null ? null : [
                'id' => $currency->id,
                'code' => $currency->code ?? null,
                'symbol' => $currency->symbol ?? null,
                'thousand_separator' => $currency->thousand_separator ?? null,
                'decimal_separator' => $currency->decimal_separator ?? null,
            ],
            'permissions' => $this->resolvePermissions($user),
            'locations' => $this->resolveLocations($user),
            'financial_year' => $this->resolveFinancialYear($business?->id),
        ];

        return $context;
    }

    /**
     * Spatie's `HasRoles` trait is mixed into `App\User` (see
     * `app/User.php`), so `getAllPermissions()` is always callable. The
     * call may still fail in environments where the spatie tables are
     * absent (e.g. partial test fixtures) — fall back to `[]` so the
     * pipeline continues.
     */
    private function resolvePermissions($user): array
    {
        try {
            return $user->getAllPermissions()->pluck('name')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Use `BusinessLocation::forDropdown()` would require auth() helpers
     * and the `selling_price_groups` table; keep this lookup minimal so
     * lightweight tests don't need the full schema.
     */
    private function resolveLocations($user): array
    {
        if ($user->business_id === null) {
            return [];
        }

        try {
            return BusinessLocation::where('business_id', $user->business_id)
                ->select('id', 'name')
                ->get()
                ->map(fn ($loc) => ['id' => $loc->id, 'name' => $loc->name])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * `BusinessUtil::getCurrentFinancialYear()` reads `fy_start_month`
     * from the business row; if the column or the row is missing, return
     * `null` so callers know the value isn't computable rather than
     * blowing up the request.
     */
    private function resolveFinancialYear(?int $businessId): ?array
    {
        if ($businessId === null) {
            return null;
        }

        try {
            return (new BusinessUtil)->getCurrentFinancialYear($businessId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
