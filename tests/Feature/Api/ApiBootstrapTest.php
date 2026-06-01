<?php

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/config` (Pest, task 2.6)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2, R9.1, R9.5.
|
| Asserts:
|   1. Authenticated GET returns 200 + canonical JSON envelope with every
|      bootstrap key (currencies, tax_rates, payment_types, locations,
|      business, currency, modules_enabled, feature_flags).
|   2. `modules_enabled` mirrors `modules_statuses.json` filtered to
|      `true` flags (read from disk, not hardcoded — keeps the test
|      coupled to the source of truth).
|   3. Disabled modules (`Essentials: false`) are excluded from the
|      response. Verified via a temporary write/restore on the real
|      `modules_statuses.json` so the production loader path is exercised.
|   4. Tax rates are scoped to the authenticated user's business — rows
|      seeded against another business id never leak into the response.
|   5. Unauthenticated requests are rejected by `auth:sanctum` with the
|      canonical 401 envelope.
|
| The schema setup mirrors `tests/Feature/Api/SetSessionDataApiTest.php`
| with one addition: a minimal `tax_rates` table so the per-business
| filter (R8.5 read via `pos_context('business.id')`) can be exercised.
*/

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('database.connections.mysql', config('database.connections.sqlite'));

    config()->set('session.driver', 'array');
    config()->set('sanctum.stateful', ['127.0.0.1:8000', 'localhost:8000']);

    DB::purge('sqlite');
    DB::purge('mysql');

    Schema::create('currencies', function ($t) {
        $t->increments('id');
        $t->string('country', 100);
        $t->string('currency', 100);
        $t->string('code', 25);
        $t->string('symbol', 25);
        $t->string('thousand_separator', 10)->nullable();
        $t->string('decimal_separator', 10)->nullable();
        $t->timestamps();
    });
    Schema::create('business', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('currency_id')->unsigned();
        $t->string('time_zone')->default('UTC');
        $t->boolean('is_active')->default(true);
        $t->integer('fy_start_month')->unsigned()->default(1);
        $t->timestamps();
    });
    Schema::create('users', function ($t) {
        $t->increments('id');
        $t->string('user_type')->default('user');
        $t->string('surname')->nullable();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->string('username')->unique();
        $t->string('email')->nullable();
        $t->string('password');
        $t->string('language', 7)->default('en');
        $t->string('remember_token', 100)->nullable();
        $t->integer('business_id')->unsigned()->nullable();
        $t->string('status')->default('active');
        $t->boolean('allow_login')->default(true);
        $t->timestamp('deleted_at')->nullable();
        $t->timestamps();
    });
    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });
    // Minimal `tax_rates` table — only the columns the controller selects
    // plus `business_id` for the per-business filter. Mirrors the prod
    // migration `2017_07_26_110000_create_tax_rates_table.php` shape.
    Schema::create('tax_rates', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->decimal('amount', 8, 4);
        $t->integer('business_id')->unsigned();
        $t->boolean('is_tax_group')->default(false);
        $t->boolean('for_tax_group')->default(true);
        $t->timestamps();
    });

    $currencyId = DB::table('currencies')->insertGetId([
        'country' => 'United States',
        'currency' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ]);

    $business = Business::create([
        'name' => 'Acme POS',
        'currency_id' => $currencyId,
        'time_zone' => 'UTC',
        'is_active' => true,
        'fy_start_month' => 1,
    ]);

    $user = User::create([
        'user_type' => 'user',
        'surname' => 'Mr',
        'first_name' => 'Alice',
        'last_name' => 'Cashier',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'password' => Hash::make('correct-horse-battery'),
        'business_id' => $business->id,
        'status' => 'active',
        'allow_login' => true,
    ]);

    test()->user = $user;
    test()->business = $business;

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

/**
 * Helper: authenticate the seeded user via the live login endpoint so the
 * Sanctum stateful cookie flow is exercised end-to-end.
 */
function loginSeededUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Helper: read `modules_statuses.json` from disk and return the keys
 * whose flag is true. Mirrors the controller's logic but keeps the test
 * coupled to the source-of-truth file rather than a hardcoded list.
 */
function expectedEnabledModules(): array
{
    $raw = file_get_contents(base_path('modules_statuses.json'));
    $decoded = json_decode($raw, true);

    $enabled = [];
    foreach ($decoded as $module => $flag) {
        if ($flag === true) {
            $enabled[] = $module;
        }
    }

    return $enabled;
}

it('returns the bootstrap envelope for an authenticated user', function () {
    loginSeededUser();

    $response = $this->getJson('/api/v1/config');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'currencies' => ['*' => ['id', 'code', 'symbol', 'country', 'currency']],
            'tax_rates',
            'payment_types' => ['*' => ['key', 'label']],
            'locations',
            'business',
            'currency',
            'modules_enabled',
            'feature_flags' => ['offline_first', 'desktop_app', 'thermal_printer_enabled'],
        ],
    ]);

    // Currencies includes the seeded USD row.
    $codes = collect($response->json('data.currencies'))->pluck('code')->all();
    expect($codes)->toContain('USD');

    // Business + currency context flow through from `SetSessionDataApi`
    // (R8.5: must come from `pos_context()`, not session()).
    $response->assertJsonPath('data.business.id', test()->business->id);
    $response->assertJsonPath('data.business.name', 'Acme POS');
    $response->assertJsonPath('data.currency.code', 'USD');

    // `modules_enabled` matches `modules_statuses.json` filtered to true
    // — read from disk so the assertion never drifts from the file.
    $expected = expectedEnabledModules();
    $actual = $response->json('data.modules_enabled');
    expect($actual)->toBeArray();
    expect(array_values(array_diff($expected, $actual)))->toBe([]);
    expect(array_values(array_diff($actual, $expected)))->toBe([]);

    // Feature flags are the v1 hardcoded values; thermal_printer_enabled
    // stays false until phase 10 wires it to real config.
    $response->assertJsonPath('data.feature_flags.offline_first', true);
    $response->assertJsonPath('data.feature_flags.desktop_app', true);
    $response->assertJsonPath('data.feature_flags.thermal_printer_enabled', false);

    // Payment types: the canonical key set the SPA needs immediately.
    $paymentKeys = collect($response->json('data.payment_types'))->pluck('key')->all();
    expect($paymentKeys)->toContain('cash', 'card', 'cheque', 'bank_transfer', 'advance');
});

it('rejects unauthenticated requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/config');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('excludes disabled modules from modules_enabled', function () {
    loginSeededUser();

    $path = base_path('modules_statuses.json');
    $original = file_get_contents($path);

    try {
        // Flip Essentials to false; every other flag is preserved so the
        // rest of the response stays valid for assertions.
        $modified = json_decode($original, true);
        $modified['Essentials'] = false;
        file_put_contents(
            $path,
            json_encode($modified, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $response = $this->getJson('/api/v1/config');

        $response->assertStatus(200);
        $modulesEnabled = $response->json('data.modules_enabled');
        expect($modulesEnabled)->toBeArray();
        expect($modulesEnabled)->not->toContain('Essentials');

        // Sanity: at least one other module that was true in the original
        // file is still enabled, proving we excluded only the false flag.
        expect($modulesEnabled)->toContain('Accounting');
    } finally {
        // Always restore the file so a failed assertion above does not
        // corrupt the working copy of `modules_statuses.json`.
        file_put_contents($path, $original);
    }
});

it('scopes tax rates to the authenticated user\'s business', function () {
    // Seed two tax rates: one for Alice's business, one for an unrelated
    // business id. Only the first must appear in the response.
    DB::table('tax_rates')->insert([
        [
            'name' => 'VAT 10%',
            'amount' => 10.0000,
            'business_id' => test()->business->id,
            'is_tax_group' => false,
            'for_tax_group' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'Other-business tax (must not leak)',
            'amount' => 99.0000,
            'business_id' => test()->business->id + 999,
            'is_tax_group' => false,
            'for_tax_group' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    loginSeededUser();

    $response = $this->getJson('/api/v1/config');
    $response->assertStatus(200);

    $taxRates = $response->json('data.tax_rates');
    expect($taxRates)->toBeArray();

    $names = collect($taxRates)->pluck('name')->all();
    expect($names)->toContain('VAT 10%');
    expect($names)->not->toContain('Other-business tax (must not leak)');

    // Each row exposes only the safe-to-publish fields.
    $row = collect($taxRates)->firstWhere('name', 'VAT 10%');
    expect($row)->toMatchArray([
        'name' => 'VAT 10%',
        'amount' => 10.0,
    ]);
    expect($row)->toHaveKey('id');
});
