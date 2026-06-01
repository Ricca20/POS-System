<?php

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET / PUT /api/v1/business/settings` (Pest, task 3.1)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2, R8.4.
|
| Asserts:
|   1. Authenticated GET returns 200 + the BusinessResource shape with the
|      embedded currency.
|   2. Unauthenticated GET returns the canonical 401 envelope.
|   3. Authenticated PUT with a valid partial body (just `name` +
|      `theme_color`) returns 200, the response reflects the change, and
|      the DB row is updated.
|   4. PUT with an invalid `currency_id` (non-existent FK) returns the
|      canonical 422 `validation_failed` envelope.
|   5. PUT with a field outside the rules schema is silently ignored;
|      valid sibling fields still apply.
|   6. PUT without the `business_settings.access` permission returns the
|      canonical 403 envelope (Gate denies before validation runs).
|
| Permission gating note: rather than stand up the Spatie tables (5 of
| them, with FKs into `business`), we use `Gate::define` to short-circuit
| the `business_settings.access` check. Spatie's `Gate::before` callback
| (registered by `PermissionServiceProvider`) calls
| `$user->checkPermissionTo($ability)` which returns `false` when the
| permission row is missing — `false ?: null` evaluates to `null`, so
| Laravel falls through to defined gates. Defining the gate here is
| therefore a clean override that doesn't fight Spatie. The "no
| permission" test simply omits the `Gate::define` call, in which case
| `$user->can(...)` returns false and the FormRequest's `authorize()`
| method denies the call.
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

    // The `business` table mirrors the production columns the SPA touches
    // through this endpoint plus the standard scaffold (id + timestamps).
    // No legacy column is required for the partial-update flow under
    // test, so the schema stays tight.
    Schema::create('business', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('currency_id')->unsigned();
        $t->date('start_date')->nullable();
        $t->string('time_zone')->default('UTC');
        $t->integer('fy_start_month')->unsigned()->default(1);
        $t->string('accounting_method')->default('fifo');
        $t->string('tax_label_1')->nullable();
        $t->string('tax_number_1')->nullable();
        $t->string('tax_label_2')->nullable();
        $t->string('tax_number_2')->nullable();
        $t->decimal('default_profit_percent', 8, 2)->default(0);
        $t->integer('default_sales_tax')->unsigned()->nullable();
        $t->decimal('default_sales_discount', 8, 2)->default(0);
        $t->string('sell_price_tax')->default('includes');
        $t->string('sku_prefix')->nullable();
        $t->integer('transaction_edit_days')->unsigned()->default(30);
        $t->string('currency_symbol_placement')->default('before');
        $t->integer('currency_precision')->unsigned()->default(2);
        $t->integer('quantity_precision')->unsigned()->default(2);
        $t->string('date_format')->default('m/d/Y');
        $t->string('time_format')->default('24');
        $t->string('theme_color')->nullable();
        $t->boolean('enable_rp')->default(false);
        $t->string('rp_name')->nullable();
        $t->decimal('amount_for_unit_rp', 10, 2)->default(1);
        $t->boolean('enable_brand')->default(true);
        $t->boolean('enable_category')->default(true);
        $t->boolean('enable_sub_category')->default(true);
        $t->boolean('enable_price_tax')->default(true);
        $t->boolean('is_active')->default(true);
        $t->string('logo')->nullable();
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
    // `tax_rates` is needed by the `default_sales_tax => exists:tax_rates,id`
    // rule in `UpdateBusinessSettingsRequest`. Tests that don't touch that
    // field still benefit from the table existing so the rule resolver
    // doesn't blow up on a missing relation.
    Schema::create('tax_rates', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->decimal('amount', 8, 4);
        $t->integer('business_id')->unsigned();
        $t->timestamps();
    });

    // Spatie permission tables — required because `PermissionServiceProvider`
    // installs a `Gate::before` callback that calls `$user->checkPermissionTo()`
    // for every gate check, which in turn queries the `permissions` table.
    // Even if we never seed a permission row, the table must exist so the
    // SELECT inside `PermissionRegistrar::loadPermissions()` doesn't blow up
    // with "no such table". With empty tables, `findByName` throws
    // `PermissionDoesNotExist`, `checkPermissionTo` swallows that and returns
    // `false`, the callback returns `null`, and Laravel falls through to any
    // gate defined via `Gate::define()` in the individual test cases.
    Schema::create('permissions', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('roles', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('guard_name');
        $t->integer('business_id')->unsigned();
        $t->boolean('is_default')->default(0);
        $t->timestamps();
    });
    Schema::create('model_has_permissions', function ($t) {
        $t->integer('permission_id')->unsigned();
        $t->morphs('model');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function ($t) {
        $t->integer('role_id')->unsigned();
        $t->morphs('model');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function ($t) {
        $t->integer('permission_id')->unsigned();
        $t->integer('role_id')->unsigned();
        $t->primary(['permission_id', 'role_id']);
    });

    // Use the array cache store so each test starts with a clean spatie
    // cache; otherwise a permission registered in one test could leak
    // into the next via the shared cache key.
    config()->set('permission.cache.store', 'array');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $currencyId = DB::table('currencies')->insertGetId([
        'country' => 'United States',
        'currency' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ]);

    $altCurrencyId = DB::table('currencies')->insertGetId([
        'country' => 'United Kingdom',
        'currency' => 'British Pound',
        'code' => 'GBP',
        'symbol' => '£',
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
    test()->currencyId = $currencyId;
    test()->altCurrencyId = $altCurrencyId;

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    // Eloquent caches the column listing per model class in a static
    // array (`Model::$guardableColumns`) the first time `isGuarded()` is
    // called. That cache is keyed by class name and never refreshed,
    // so a previous test in the same Pest run that booted the
    // `App\Business` model against a leaner schema (e.g. AuthFlowTest's
    // `business` table without `theme_color`) leaves the cache pinned
    // to that smaller column set. Subsequent fills against our richer
    // schema would then silently drop columns the cache doesn't list.
    //
    // Clear the cache via reflection so this test's fresh in-memory
    // SQLite schema is honoured. There is no public API for this in
    // Laravel 9.
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

/**
 * Authenticate the seeded user via the live login endpoint so the Sanctum
 * stateful cookie flow is exercised end-to-end.
 */
function loginBusinessUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant the `business_settings.access` permission to every authenticated
 * user via Gate. Spatie's Gate::before returns null when the permission
 * row is missing, so Laravel falls through to this defined gate.
 */
function grantBusinessSettingsAccess(): void
{
    Gate::define('business_settings.access', fn ($user) => true);
}

it('returns the business settings envelope for an authenticated user', function () {
    loginBusinessUser();

    $response = $this->getJson('/api/v1/business/settings');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // Top-level key set: every field declared by BusinessResource is
    // present so the SPA never has to deal with `undefined`.
    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'currency_id',
            'time_zone',
            'fy_start_month',
            'accounting_method',
            'tax_label_1',
            'tax_number_1',
            'tax_label_2',
            'tax_number_2',
            'default_profit_percent',
            'default_sales_tax',
            'default_sales_discount',
            'sell_price_tax',
            'sku_prefix',
            'transaction_edit_days',
            'currency_symbol_placement',
            'currency_precision',
            'quantity_precision',
            'date_format',
            'time_format',
            'theme_color',
            'enable_rp',
            'rp_name',
            'amount_for_unit_rp',
            'enable_brand',
            'enable_category',
            'enable_sub_category',
            'enable_price_tax',
            'is_active',
            'logo',
            'created_at',
            'updated_at',
            'currency' => ['id', 'code', 'symbol', 'country', 'currency'],
        ],
    ]);

    $response->assertJsonPath('data.id', test()->business->id);
    $response->assertJsonPath('data.name', 'Acme POS');
    $response->assertJsonPath('data.currency_id', test()->currencyId);
    $response->assertJsonPath('data.is_active', true);

    // Embedded currency fields come from the related Currency row.
    $response->assertJsonPath('data.currency.code', 'USD');
    $response->assertJsonPath('data.currency.symbol', '$');
    $response->assertJsonPath('data.currency.country', 'United States');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/business/settings');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('updates a business profile from a partial PUT payload', function () {
    grantBusinessSettingsAccess();
    loginBusinessUser();

    // Partial body: only name + theme_color. Every other column on the
    // existing row must remain untouched.
    $response = $this->putJson('/api/v1/business/settings', [
        'name' => 'Acme POS Renamed',
        'theme_color' => '#ff0033',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', test()->business->id);
    $response->assertJsonPath('data.name', 'Acme POS Renamed');
    $response->assertJsonPath('data.theme_color', '#ff0033');

    // DB row reflects the change AND only those columns changed.
    $row = DB::table('business')->where('id', test()->business->id)->first();
    expect($row->name)->toBe('Acme POS Renamed');
    expect($row->theme_color)->toBe('#ff0033');
    // Sanity: a column we did NOT submit kept its original value.
    expect($row->time_zone)->toBe('UTC');
});

it('updates currency_id and reflects the new currency in the embedded resource', function () {
    grantBusinessSettingsAccess();
    loginBusinessUser();

    $response = $this->putJson('/api/v1/business/settings', [
        'currency_id' => test()->altCurrencyId,
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.currency_id', test()->altCurrencyId);
    $response->assertJsonPath('data.currency.code', 'GBP');
    $response->assertJsonPath('data.currency.symbol', '£');
});

it('returns the canonical 422 envelope when currency_id does not exist', function () {
    grantBusinessSettingsAccess();
    loginBusinessUser();

    $response = $this->putJson('/api/v1/business/settings', [
        'currency_id' => 999999,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['currency_id'],
    ]);

    // The DB row is unchanged.
    $row = DB::table('business')->where('id', test()->business->id)->first();
    expect((int) $row->currency_id)->toBe((int) test()->currencyId);
});

it('silently drops fields outside the rule set while still applying valid ones', function () {
    grantBusinessSettingsAccess();
    loginBusinessUser();

    $response = $this->putJson('/api/v1/business/settings', [
        'name' => 'New Acme Name',
        // Intentionally not declared in UpdateBusinessSettingsRequest::rules().
        // The form request's validated() output excludes it, so it must
        // never reach the DB.
        'random_field' => 'should-be-ignored',
        'arbitrary_secret' => 'leak',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.name', 'New Acme Name');
    // The resource never exposes the spurious keys.
    expect($response->json('data'))->not->toHaveKey('random_field');
    expect($response->json('data'))->not->toHaveKey('arbitrary_secret');

    $row = DB::table('business')->where('id', test()->business->id)->first();
    expect($row->name)->toBe('New Acme Name');
    // No `random_field` column exists; the SQLite test harness would have
    // raised an error if Laravel had tried to write it. The lack of error
    // (200 response above) is itself the assertion.
});

it('rejects PUT without business_settings.access with a 403 envelope', function () {
    // Note: NO `grantBusinessSettingsAccess()` call here. The user has no
    // role/permission, so `$user->can('business_settings.access')` is
    // false and `UpdateBusinessSettingsRequest::authorize()` denies.
    loginBusinessUser();

    $response = $this->putJson('/api/v1/business/settings', [
        'name' => 'Should Not Apply',
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'forbidden');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // Underlying row stays the same.
    $row = DB::table('business')->where('id', test()->business->id)->first();
    expect($row->name)->toBe('Acme POS');
});
