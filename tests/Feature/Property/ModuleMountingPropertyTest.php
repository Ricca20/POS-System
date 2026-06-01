<?php

use App\Providers\ModuleApiRouteProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Property test: module mounting completeness (Pest, task 2.10)
|--------------------------------------------------------------------------
|
| Validates: R9.1, R9.2, R9.3.
| Property: P6 (Module API mounting completeness).
|
| Property statement (from design.md):
|
|   For every module name `m` where modules_statuses.json[m] = true AND
|   Modules/{m}/Routes/api.php exists on disk, every route declared in
|   that file SHALL be reachable under /api/v1/modules/{kebab(m)}/...
|   after Laravel boots. Conversely, every other module SHALL NOT
|   contribute any /api/v1/modules/{kebab(m)}/* route.
|
| Strategy
| --------
|
| The workspace ships `fakerphp/faker` but does not ship `pest-plugin-faker`.
| Per task instructions we use `\Faker\Factory::create()->seed($i)` for
| deterministic reproduction without adding a new dependency. The test is a
| single Pest `it()` block that loops 200 iterations (above the spec's >=100
| minimum), where each iteration:
|
|   1. Picks a random subset of the 19 canonical module names. For each
|      name, picks a random `flag` (true|false) and a random `apiFile`
|      flag (true|false). The expected mounted set is
|      { name : flag = true AND apiFile = true }. R9.3 (skip when no
|      api file) and R9.1 (skip when flag = false) are both exercised
|      every iteration with high probability for any given name.
|
|   2. Stands up a fixture overlay under tests/fixtures/modules-prop-{i}-*
|      containing per-module folders. When apiFile is true, writes a
|      Routes/api.php declaring a single GET /health route. When apiFile
|      is false, half the time creates the module folder without
|      Routes/api.php (R9.3 with folder), and half the time creates no
|      folder at all (R9.3 without folder).
|
|   3. Re-points config('desktop.modules_statuses_path') and
|      config('desktop.modules_base_path') at the overlay and re-boots a
|      fresh ModuleApiRouteProvider instance against it.
|
|   4. Captures the diff of registered route URIs (afterUris minus
|      beforeUris) and asserts it equals exactly the expected set of
|      api/v1/modules/{kebab(m)}/health URIs computed from the random
|      decisions above.
|
| Uniqueness across iterations
| ----------------------------
|
| Laravel's router does not deduplicate routes; calling Route::get for
| the same URI twice produces two route entries. PHP's array_diff()
| however does not respect multiplicity, so if two iterations reused the
| same module name they would collide and the diff would underreport
| iteration N's contribution. To avoid that we mangle each iteration's
| module names with an "Iter{i}" prefix (e.g. "Iter17AssetManagement").
| The kebab transformation (R9.2) is still fully exercised because
| Str::kebab("Iter17AssetManagement") = "iter17-asset-management" relies
| on the same logic as the production names; the production-name kebab
| mapping is separately covered by tests/Feature/Api/ModuleAutoMountTest.php.
|
| Cleanup
| -------
|
| Each iteration is wrapped in try { ... } finally { rrmdir($fixtureRoot) }
| so a failing iteration still cleans its overlay. After the loop the
| sweep helper deletes any leftover modules-prop-* directories and the
| now-empty tests/fixtures parent so `git status` stays clean.
*/

/**
 * Recursive directory delete. Same shape as the helper in
 * `tests/Feature/Api/ModuleAutoMountTest.php` but namespaced with a
 * different function name so the two files can coexist without
 * redeclaration errors.
 */
if (! function_exists('module_mounting_prop_rrmdir')) {
    function module_mounting_prop_rrmdir(string $path): void
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
                module_mounting_prop_rrmdir($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}

/**
 * Sweep every leftover `tests/fixtures/modules-prop-*` directory. Used
 * before and after the property loop so a previous run that died with a
 * hard failure doesn't leak state into the workspace.
 */
if (! function_exists('module_mounting_prop_sweep_fixtures')) {
    function module_mounting_prop_sweep_fixtures(): void
    {
        $base = base_path('tests/fixtures');
        if (! is_dir($base)) {
            return;
        }

        foreach (glob($base.'/modules-prop-*') ?: [] as $stale) {
            if (is_dir($stale)) {
                module_mounting_prop_rrmdir($stale);
            }
        }

        // Remove the parent if it is now empty so the workspace stays
        // clean for `git status`.
        $remaining = @scandir($base);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($base);
        }
    }
}

beforeEach(function () {
    module_mounting_prop_sweep_fixtures();
});

afterEach(function () {
    module_mounting_prop_sweep_fixtures();
});

it('mounts exactly the modules whose flag is true and whose Routes/api.php exists, across 200 random fixtures', function () {
    /*
    | The 19 canonical module names from modules_statuses.json. Each
    | iteration uses an "Iter{i}"-prefixed variant of these so route
    | URIs don't collide across iterations (see file header).
    */
    $canonicalModules = [
        'Essentials', 'Accounting', 'AssetManagement', 'Cms', 'Connector',
        'Crm', 'Ecommerce', 'FieldForce', 'Manufacturing', 'ProductCatalogue',
        'Project', 'Repair', 'Spreadsheet', 'Superadmin', 'Woocommerce',
        'AiAssistance', 'Hms', 'InboxReport', 'CustomDashboard',
    ];

    $iterations = 200;

    for ($i = 0; $i < $iterations; $i++) {
        $faker = \Faker\Factory::create();
        $faker->seed($i);

        // Subset of the 19 modules to even include in this iteration's
        // statuses payload. Subset size 0..19 covers the empty-payload
        // edge (R9.1: missing key => not enabled) too.
        $subsetSize = $faker->numberBetween(0, 19);
        $iterSubset = $faker->randomElements($canonicalModules, $subsetSize);

        // Per-iteration module names. Prefixing with "Iter{i}" ensures
        // distinct kebab URIs across iterations so the
        // beforeUris/afterUris diff captures exactly this iteration's
        // contribution (see file header for the array_diff multiplicity
        // explanation).
        $statuses = [];        // mangledName => bool flag
        $apiFiles = [];        // mangledName => bool (Routes/api.php exists)
        $folderOnly = [];      // mangledName => bool (folder exists but no api file)

        foreach ($iterSubset as $name) {
            $mangled = 'Iter'.$i.$name;

            $flag = $faker->boolean(70);
            $hasApiFile = $faker->boolean(70);
            // When the api file is absent, half the time we still
            // create the module folder so R9.3 is exercised in both
            // its "folder exists but no api.php" and "folder absent"
            // sub-cases.
            $folderOnlyChoice = (! $hasApiFile) && $faker->boolean(50);

            $statuses[$mangled] = $flag;
            $apiFiles[$mangled] = $hasApiFile;
            $folderOnly[$mangled] = $folderOnlyChoice;
        }

        // Build the on-disk overlay.
        $fixtureRoot = base_path('tests/fixtures/modules-prop-'.$i.'-'.uniqid('', true));

        // The expected set of route URIs this iteration should add.
        $expected = [];

        try {
            @mkdir($fixtureRoot, 0777, true);

            foreach ($statuses as $mangled => $flag) {
                if ($apiFiles[$mangled]) {
                    @mkdir($fixtureRoot.DIRECTORY_SEPARATOR.$mangled.DIRECTORY_SEPARATOR.'Routes', 0777, true);
                    file_put_contents(
                        $fixtureRoot.DIRECTORY_SEPARATOR.$mangled.DIRECTORY_SEPARATOR.'Routes'.DIRECTORY_SEPARATOR.'api.php',
                        '<?php
use Illuminate\Support\Facades\Route;
Route::get("/health", function () {
    return response()->json(["data" => ["module" => "ok"]]);
});
'
                    );
                } elseif ($folderOnly[$mangled]) {
                    @mkdir($fixtureRoot.DIRECTORY_SEPARATOR.$mangled, 0777, true);
                }
                // else: neither folder nor api file — provider must
                // still skip silently (R9.3 sub-case).

                if ($flag === true && $apiFiles[$mangled]) {
                    $expected[] = 'api/v1/modules/'.Str::kebab($mangled).'/health';
                }
            }

            $statusesFile = $fixtureRoot.DIRECTORY_SEPARATOR.'modules_statuses.json';
            file_put_contents($statusesFile, json_encode($statuses));

            config()->set('desktop.modules_statuses_path', $statusesFile);
            config()->set('desktop.modules_base_path', $fixtureRoot);

            // Snapshot the route table BEFORE this iteration's boot so
            // we can diff exactly the new routes the provider added.
            // The route table accumulates across iterations but our
            // mangled module names guarantee no URI collisions, so
            // array_diff captures the right contribution.
            $beforeUris = collect(Route::getRoutes()->getRoutes())
                ->map(fn ($r) => $r->uri())
                ->values()
                ->all();

            (new ModuleApiRouteProvider(app()))->boot();

            $afterUris = collect(Route::getRoutes()->getRoutes())
                ->map(fn ($r) => $r->uri())
                ->values()
                ->all();

            $newUris = array_values(array_diff($afterUris, $beforeUris));

            sort($newUris);
            sort($expected);

            // Property assertion. The error message embeds the seed (i)
            // and the per-module decisions so any failing iteration is
            // trivially reproducible: re-run the loop with $i pinned
            // to the printed seed.
            expect($newUris)->toBe(
                $expected,
                "Iteration $i (seed=$i): expected mounted URIs ".json_encode($expected)
                .' but got '.json_encode($newUris)
                .'; statuses='.json_encode($statuses)
                .'; apiFiles='.json_encode($apiFiles)
                .'; folderOnly='.json_encode($folderOnly)
            );
        } finally {
            module_mounting_prop_rrmdir($fixtureRoot);
        }
    }
});
