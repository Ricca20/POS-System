<?php

use App\Providers\ModuleApiRouteProvider;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ModuleApiRouteProvider auto-mount (Pest, task 2.9)
|--------------------------------------------------------------------------
|
| Validates: R9.1, R9.2, R9.3, R9.4.
|
| Asserts that `App\Providers\ModuleApiRouteProvider` correctly:
|
|   - mounts every enabled module's `Routes/api.php` under
|     `/api/v1/modules/{kebab(Name)}` (R9.1, R9.2)
|   - applies the `["api", "auth:sanctum", "SetSessionDataApi"]`
|     middleware stack — proven via a 401 `unauthenticated` envelope
|     from a route that would otherwise return 200 (R9.2)
|   - silently skips an enabled module whose `Routes/api.php` is absent
|     (R9.3)
|   - silently skips an enabled module whose folder does not exist on
|     disk (R9.3)
|   - skips a module whose flag is `false` (R9.1)
|   - converts CamelCase module names to kebab-case in the URL prefix
|     (R9.2 — `AssetManagement` → `asset-management`)
|
| Property P6 (the universally quantified mounting completeness claim)
| is exercised by the dedicated property test in task 2.10.
|
| Each test uses a per-test fixture overlay under `tests/fixtures/`
| pointed at via `desktop.modules_statuses_path` and
| `desktop.modules_base_path`. The production `ModuleApiRouteProvider`
| already booted during application construction; because the workspace
| has no `Modules/` directory (it materialises in tasks 7.7.1–7.7.19),
| that boot was a silent no-op. Re-booting a fresh provider instance
| against the test config overlay is what wires up the fixture routes.
*/

/**
 * Recursively delete a directory tree. Pest tests run with no
 * `RefreshDatabase` so we manage filesystem cleanup ourselves;
 * `Symfony\Component\Filesystem\Filesystem::remove()` would also work
 * but pulling it in here would add a dependency the rest of the test
 * suite does not use.
 */
if (! function_exists('module_auto_mount_test_rrmdir')) {
    function module_auto_mount_test_rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = @scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$item;

            if (is_dir($full) && ! is_link($full)) {
                module_auto_mount_test_rrmdir($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}

/**
 * Sweep every `tests/fixtures/modules-overlay-*` directory. Used at the
 * top of every `beforeEach` so a previous-test cleanup that didn't run
 * (Pest's `afterEach` proxy can rebind `$this` and lose `test()->...`
 * properties) never leaks fixtures into subsequent runs.
 */
if (! function_exists('module_auto_mount_test_sweep_fixtures')) {
    function module_auto_mount_test_sweep_fixtures(): void
    {
        $base = base_path('tests/fixtures');
        if (! is_dir($base)) {
            return;
        }

        foreach (glob($base.'/modules-overlay-*') ?: [] as $stale) {
            if (is_dir($stale)) {
                module_auto_mount_test_rrmdir($stale);
            }
        }

        // Remove the parent if it is now empty so the workspace stays
        // clean for `git status` (the fixtures directory exists only
        // for these tests).
        $remaining = @scandir($base);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($base);
        }
    }
}

beforeEach(function () {
    // Sweep any stale fixtures left behind by previous runs (or by a
    // prior test whose afterEach was skipped due to a hard failure).
    module_auto_mount_test_sweep_fixtures();

    // Per-test fixture root so two tests cannot collide if Pest ever
    // parallelises this file. `uniqid('', true)` adds a microsecond
    // tail for extra collision resistance.
    test()->fixtureRoot = base_path(
        'tests/fixtures/modules-overlay-'.uniqid('', true)
    );

    // SampleModule has a real `Routes/api.php` — should be mounted.
    @mkdir(test()->fixtureRoot.'/SampleModule/Routes', 0777, true);
    file_put_contents(
        test()->fixtureRoot.'/SampleModule/Routes/api.php',
        '<?php
use Illuminate\Support\Facades\Route;
Route::get("/health", function () {
    return response()->json(["data" => ["module" => "sample-module", "status" => "ok"]]);
});
'
    );

    // EmptyModule exists on disk but has no `Routes/api.php` —
    // must be silently skipped (R9.3).
    @mkdir(test()->fixtureRoot.'/EmptyModule', 0777, true);

    // NonExistentModule is declared in `modules_statuses.json` but
    // its folder is intentionally never created — must also be
    // silently skipped.

    // DisabledModule's flag is false — must not be mounted regardless
    // of file presence.

    $statusesFile = test()->fixtureRoot.'/modules_statuses.json';
    file_put_contents($statusesFile, json_encode([
        'SampleModule' => true,
        'EmptyModule' => true,
        'NonExistentModule' => true,
        'DisabledModule' => false,
    ]));

    config()->set('desktop.modules_statuses_path', $statusesFile);
    config()->set('desktop.modules_base_path', test()->fixtureRoot);

    // Re-boot a fresh provider instance against the new config. The
    // production provider that ran during app construction saw no
    // `Modules/` tree on disk and therefore registered nothing, so the
    // router state is dominated entirely by this boot.
    (new ModuleApiRouteProvider(app()))->boot();
});

afterEach(function () {
    // Belt-and-braces: sweep every fixture dir, including the one this
    // test created. Using the global sweeper rather than test()-> means
    // cleanup is robust to Pest's `$this` rebinding inside the
    // afterEach closure.
    module_auto_mount_test_sweep_fixtures();
});

it('mounts an enabled module under /api/v1/modules/{kebab}/ with auth:sanctum applied', function () {
    // Stateful headers so Sanctum treats this as a SPA call (matches
    // the configured stateful domain in `config/sanctum.php`).
    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);

    // Hit the mounted route without authentication. If `auth:sanctum`
    // was applied (R9.2), the request is rejected with the canonical
    // 401 `unauthenticated` envelope produced by `App\Exceptions\Handler`.
    // If the middleware were missing, the closure would run and return
    // 200 — so the 401 is the fingerprint that the middleware stack
    // really wraps the route.
    $response = $this->getJson('/api/v1/modules/sample-module/health');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
    expect($response->headers->get('Content-Type'))
        ->toStartWith('application/json');
});

it('registers the route with the canonical kebab-cased prefix', function () {
    // Enumerate the registered routes (R9.2) and confirm our
    // SampleModule health route is exactly one entry under
    // `api/v1/modules/sample-module/`.
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => $uri === 'api/v1/modules/sample-module/health')
        ->values();

    expect($uris)->toHaveCount(1);
});

it('silently skips an enabled module that has no Routes/api.php', function () {
    // EmptyModule's flag is true and the folder exists, but
    // `Routes/api.php` does not — provider must skip without raising
    // (R9.3) and no route under that prefix may exist.
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/modules/empty-module'))
        ->values();

    expect($uris)->toHaveCount(0);
});

it('silently skips an enabled module whose folder does not exist on disk', function () {
    // NonExistentModule is declared `true` in modules_statuses.json
    // but its folder was never created on disk. The provider must
    // treat that the same as a missing `Routes/api.php` and skip
    // silently (R9.3) — i.e. no route under `non-existent-module/*`.
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/modules/non-existent-module'))
        ->values();

    expect($uris)->toHaveCount(0);
});

it('skips a module whose flag is false', function () {
    // DisabledModule's flag is `false` — the provider must not mount
    // even if a `Routes/api.php` were present, and certainly not when
    // (as here) the module folder also does not exist (R9.1).
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/modules/disabled-module'))
        ->values();

    expect($uris)->toHaveCount(0);
});

it('converts CamelCase module names to kebab-case in the URL prefix', function () {
    // Build a fresh fixture using the canonical CamelCase module name
    // `AssetManagement` (one of the 19 modules in
    // `modules_statuses.json`). The provider must produce the prefix
    // `api/v1/modules/asset-management/...` via `Str::kebab()` (R9.2).
    module_auto_mount_test_sweep_fixtures();
    test()->fixtureRoot = base_path(
        'tests/fixtures/modules-overlay-kebab-'.uniqid('', true)
    );

    @mkdir(test()->fixtureRoot.'/AssetManagement/Routes', 0777, true);
    file_put_contents(
        test()->fixtureRoot.'/AssetManagement/Routes/api.php',
        '<?php
use Illuminate\Support\Facades\Route;
Route::get("/health", function () {
    return response()->json(["data" => ["module" => "asset-management"]]);
});
'
    );

    $statusesFile = test()->fixtureRoot.'/modules_statuses.json';
    file_put_contents($statusesFile, json_encode([
        'AssetManagement' => true,
    ]));

    config()->set('desktop.modules_statuses_path', $statusesFile);
    config()->set('desktop.modules_base_path', test()->fixtureRoot);

    (new ModuleApiRouteProvider(app()))->boot();

    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => $uri === 'api/v1/modules/asset-management/health')
        ->values();

    expect($uris)->toHaveCount(1);
});
