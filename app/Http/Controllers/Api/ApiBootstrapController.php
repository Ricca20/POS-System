<?php

namespace App\Http\Controllers\Api;

use App\Business;
use App\Http\Controllers\Controller;
use App\Services\PaymentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * SPA bootstrap endpoint — `GET /api/v1/config`.
 *
 * Returns the static reference data the Vue SPA needs immediately after
 * login (currencies, tax rates, payment methods, business locations) plus
 * the runtime feature/module flags the shell uses to decide which routes
 * and toggles to render.
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi` (see `routes/api.php`)
 * so this controller may rely on `pos_context()` (R8.5) without invoking
 * the legacy `session()` helper anywhere (R8.4).
 *
 * Validates: R8.1, R8.2, R9.1, R9.5.
 */
class ApiBootstrapController extends Controller
{
    /**
     * Path to `modules_statuses.json` relative to the project base path.
     *
     * Duplicated from `ApiAuthController` deliberately: the two endpoints
     * share semantics ("enabled module keys") but are otherwise unrelated,
     * and pulling the loader into a shared service / parent class would
     * couple the legacy `Controller` base to file-system IO. Extract into
     * a service when a third caller appears.
     */
    private const MODULES_STATUSES_FILE = 'modules_statuses.json';

    /**
     * Return the bootstrap envelope.
     *
     * Shape:
     * {
     *   "data": {
     *     "currencies":      [{id, code, symbol, country, currency}, ...],
     *     "tax_rates":       [{id, name, amount}, ...],
     *     "payment_types":   [{key, label}, ...],
     *     "locations":       [{id, name}, ...]   // copied from pos_context
     *     "business":        {id, name, ...}     // copied from pos_context
     *     "currency":        {id, code, ...}     // copied from pos_context
     *     "modules_enabled": ["Essentials", ...],
     *     "feature_flags":   {offline_first, desktop_app, thermal_printer_enabled}
     *   }
     * }
     */
    public function config(): JsonResponse
    {
        $businessId = pos_context('business.id');

        return response()->json([
            'data' => [
                'currencies' => $this->loadCurrencies(),
                'tax_rates' => $this->loadTaxRates($businessId),
                'payment_types' => $this->paymentTypes(),
                'locations' => pos_context('locations', []),
                'business' => pos_context('business'),
                'currency' => pos_context('currency'),
                'modules_enabled' => $this->enabledModuleKeys(),
                'feature_flags' => $this->featureFlags(),
            ],
        ], 200);
    }

    /**
     * The `currencies` table has no `business_id` column (see
     * `database/migrations/2017_07_25_*_create_currencies_table.php`); it
     * is a global lookup of ISO currencies, so we return all rows.
     *
     * Wrapped in try/catch so a partially-migrated test fixture (no
     * `currencies` table) returns `[]` instead of crashing the endpoint.
     */
    private function loadCurrencies(): array
    {
        try {
            return DB::table('currencies')
                ->select('id', 'code', 'symbol', 'country', 'currency')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'code' => $row->code,
                    'symbol' => $row->symbol,
                    'country' => $row->country,
                    'currency' => $row->currency,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Tax rates scoped to the current business id (R8.5: business context
     * comes from `pos_context()`, never from `session()`).
     *
     * The `tax_rates` table is created by Laravel migrations in production
     * but may be absent in lightweight test fixtures; tolerate that with a
     * try/catch so a missing table never crashes `/api/v1/config`.
     */
    private function loadTaxRates($businessId): array
    {
        if ($businessId === null) {
            return [];
        }

        try {
            return DB::table('tax_rates')
                ->where('business_id', $businessId)
                ->select('id', 'name', 'amount')
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'amount' => (float) $row->amount,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Mirrors the keys returned by `App\Utils\Util::payment_types()` in
     * its default mode (no location, `show_advance = true`). Delegates to
     * `App\Services\PaymentTypes` so this endpoint and
     * `PosApiController::config()` (task 5.1) share one source of truth
     * for the canonical key set.
     *
     * Custom-payment labels (`custom_pay_1..3`) come from the business
     * `custom_labels` JSON in the legacy code; the service merges those
     * automatically when a business is supplied.
     */
    private function paymentTypes(): array
    {
        $businessId = pos_context('business.id');
        $business = $businessId === null ? null : Business::find($businessId);

        return PaymentTypes::defaults($business);
    }

    /**
     * Read `modules_statuses.json` and return the keys whose flag is
     * `true`. Disabled modules are excluded from the response (R9.1, R9.5).
     *
     * Returns `[]` when the file is missing or unreadable so a corrupted
     * install never breaks the bootstrap endpoint; the SPA treats absence
     * the same as "no module enabled".
     *
     * Logic intentionally duplicates `ApiAuthController::enabledModuleKeys`
     * — see class-level comment for rationale.
     */
    private function enabledModuleKeys(): array
    {
        $path = base_path(self::MODULES_STATUSES_FILE);

        if (! is_readable($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $enabled = [];
        foreach ($decoded as $module => $flag) {
            if ($flag === true) {
                $enabled[] = (string) $module;
            }
        }

        return $enabled;
    }

    /**
     * Build-time feature flags hardcoded for v1. Wired to real `config()`
     * lookups in Phase 8 / Phase 10 once the desktop config service and
     * thermal-printer support land.
     */
    private function featureFlags(): array
    {
        return [
            'offline_first' => true,
            'desktop_app' => true,
            'thermal_printer_enabled' => false,
        ];
    }
}
