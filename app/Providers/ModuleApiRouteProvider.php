<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Auto-discovers and mounts the API surface declared by every enabled
 * module under `Modules/{Name}/Routes/api.php`.
 *
 * On boot:
 *
 *   1. Reads `modules_statuses.json` from
 *      `config('desktop.modules_statuses_path')`, falling back to
 *      `base_path('modules_statuses.json')`. If the file is missing,
 *      unreadable, or not a JSON object, the provider is a silent no-op
 *      so Laravel boots cleanly from a fresh checkout that has no
 *      `Modules/` tree on disk yet.
 *   2. For every entry whose flag is exactly `true`, builds the path
 *      `{modules_base_path}/{Name}/Routes/api.php` (where
 *      `modules_base_path` defaults to `base_path('Modules')` and is
 *      overridable via `config('desktop.modules_base_path')` so tests
 *      can stand up a fixture overlay without touching the real module
 *      tree).
 *   3. If that file exists, mounts every route declared in it under
 *      the prefix `/api/v1/modules/{kebab(Name)}` with the canonical
 *      API middleware stack `["api", "auth:sanctum", "SetSessionDataApi"]`.
 *      Modules without a `Routes/api.php` file are skipped silently
 *      (Requirement R9.3) — a module skeleton without its own API
 *      surface is a non-error state.
 *
 * The provider must be registered in `config/app.php` after
 * `App\Providers\RouteServiceProvider::class` so the router is fully
 * wired before route registration runs.
 *
 * Validates: R9.1, R9.2, R9.3, R9.4. Property P6 (fuzz test in 2.10).
 */
class ModuleApiRouteProvider extends ServiceProvider
{
    /**
     * Discover every enabled module's `Routes/api.php` and mount it under
     * `/api/v1/modules/{kebab-name}/`. Idempotent failure modes (missing
     * statuses file, malformed JSON, missing module folder) are
     * intentionally silent — see the class docblock.
     */
    public function boot(): void
    {
        $statusesPath = $this->statusesPath();

        if (! is_file($statusesPath)) {
            return;
        }

        $contents = @file_get_contents($statusesPath);
        if ($contents === false) {
            return;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return;
        }

        $modulesBase = $this->modulesBasePath();

        foreach ($decoded as $moduleName => $flag) {
            // Strict `=== true` so a string like "true" or a numeric 1
            // cannot accidentally enable a module — the contract is a
            // boolean flag (R9.1).
            if ($flag !== true) {
                continue;
            }

            // Defensive: keys are expected to be non-empty strings such
            // as `AssetManagement`. A null/empty key would resolve to a
            // path under `Modules/` itself which we must not mount.
            if (! is_string($moduleName) || $moduleName === '') {
                continue;
            }

            $apiFile = $modulesBase
                .DIRECTORY_SEPARATOR.$moduleName
                .DIRECTORY_SEPARATOR.'Routes'
                .DIRECTORY_SEPARATOR.'api.php';

            // Silent skip when the module has no API surface (R9.3).
            if (! is_file($apiFile)) {
                continue;
            }

            $kebab = Str::kebab($moduleName);

            Route::prefix('api/v1/modules/'.$kebab)
                ->middleware(['api', 'auth:sanctum', 'SetSessionDataApi'])
                ->group($apiFile);
        }
    }

    /**
     * Resolve the path to `modules_statuses.json`. The `desktop.modules_statuses_path`
     * config key wins when set so test suites can swap in a fixture file;
     * otherwise the canonical workspace-root location used by
     * `nwidart/laravel-modules` is used.
     */
    private function statusesPath(): string
    {
        $configured = config('desktop.modules_statuses_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return base_path('modules_statuses.json');
    }

    /**
     * Resolve the directory that holds `Modules/{Name}/` folders. The
     * `desktop.modules_base_path` config key is the test-time override;
     * production always uses `base_path('Modules')`.
     */
    private function modulesBasePath(): string
    {
        $configured = config('desktop.modules_base_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return base_path('Modules');
    }
}
