<?php

/*
|--------------------------------------------------------------------------
| Desktop App Configuration
|--------------------------------------------------------------------------
|
| Settings consumed by the desktop conversion (Option B) — namely the
| Laravel-side connectivity probe used by `App\Services\ConnectivityProbe`
| and the `online` middleware (`App\Http\Middleware\OnlineGuard`).
|
| The probe issues a HEAD request to a small endpoint that is reliably
| reachable from any internet-connected machine; if the request fails or
| times out, the request is considered offline and online-only routes are
| short-circuited with a 503 `offline_required` envelope.
|
| All values are environment-overridable so an installer or CI can point
| the probe at a regional fallback without code changes.
|
| Related: Requirements R12.2, R12.3 and the design's `Online_Guard`
| component (see `.kiro/specs/desktop-app-conversion/design.md`).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Connectivity Probe URL
    |--------------------------------------------------------------------------
    |
    | The endpoint hit by `ConnectivityProbe::probe()`. The default points
    | at Google's `generate_204` endpoint, which returns HTTP 204 from any
    | edge that has internet, has no payload, and is unaffected by content
    | filters. Override via `DESKTOP_CONNECTIVITY_PROBE_URL` if a deployment
    | needs a different reachability target.
    |
    */
    'connectivity_probe_url' => env('DESKTOP_CONNECTIVITY_PROBE_URL', 'https://www.gstatic.com/generate_204'),

    /*
    |--------------------------------------------------------------------------
    | Connectivity Probe Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum time the probe HTTP request is allowed to take before being
    | treated as a connectivity failure. Kept short so the OnlineGuard does
    | not stall request handling.
    |
    */
    'connectivity_probe_timeout_seconds' => (int) env('DESKTOP_CONNECTIVITY_PROBE_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Connectivity Probe Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | The probe result is cached so that bursts of requests guarded by
    | OnlineGuard do not each issue an outbound HEAD. Matches the Vue SPA's
    | 30 s heartbeat cadence (R11.1) so the server- and client-side views
    | of "online" stay roughly in sync.
    |
    */
    'connectivity_probe_cache_ttl_seconds' => (int) env('DESKTOP_CONNECTIVITY_PROBE_CACHE_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | Modules Statuses File Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the `modules_statuses.json` file consumed by
    | `App\Providers\ModuleApiRouteProvider`. Production always reads from
    | `base_path('modules_statuses.json')`; this env override exists
    | primarily so test suites can stand up a fixture overlay
    | (`tests/Feature/Api/ModuleAutoMountTest.php`) and exercise the
    | provider against synthetic module trees without mutating the real
    | source-of-truth file.
    |
    | When null/empty, the provider falls back to
    | `base_path('modules_statuses.json')`.
    |
    | Related: Requirements R9.1, R9.2, R9.3, R9.4 and the design's
    | `Module_Route_Provider` component.
    |
    */
    'modules_statuses_path' => env('DESKTOP_MODULES_STATUSES_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Modules Base Directory
    |--------------------------------------------------------------------------
    |
    | Absolute path to the directory that holds `Modules/{Name}/` folders.
    | `App\Providers\ModuleApiRouteProvider` resolves
    | `{base}/{Name}/Routes/api.php` against this value. Production always
    | reads from `base_path('Modules')`; the env override exists for the
    | same reason as `modules_statuses_path` above — a test fixture overlay
    | so the provider can be exercised against synthetic module trees.
    |
    | When null/empty, the provider falls back to `base_path('Modules')`.
    |
    | Related: Requirements R9.1, R9.2, R9.3, R9.4.
    |
    */
    'modules_base_path' => env('DESKTOP_MODULES_BASE_PATH'),

];
