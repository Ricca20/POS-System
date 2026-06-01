<?php

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `SetSessionDataApi` middleware (Pest)
|--------------------------------------------------------------------------
|
| Validates: R8.4, R8.5.
|
| The middleware (task 2.5) is the API replacement for the legacy
| `SetSessionData` web middleware. It builds the same business/user/
| permission/currency context but writes it into a request-scoped
| `app('pos.context')` container. These tests assert:
|
|   1. A probe controller mounted behind `auth:sanctum` + `SetSessionDataApi`
|      reads `business.id`, the user identity, and a permissions array via
|      the `pos_context()` helper without ever touching `session()`.
|   2. `session('user')` and `session('business')` are empty for the same
|      request — proving R8.4 (no `session()` use on the API path).
|   3. Without the middleware, `pos_context()` returns null, proving the
|      helper is gated behind the middleware and not a global side effect.
|   4. Unauthenticated requests are rejected by `auth:sanctum` before the
|      middleware ever runs.
|
| The schema setup mirrors `tests/Feature/Api/AuthFlowTest.php` — only the
| columns the auth flow actually touches are created in SQLite-in-memory.
| The Spatie permission tables are deliberately omitted: the middleware
| wraps `getAllPermissions()` in a try/catch and falls back to `[]` when
| the tables are absent, which is the contract we want to assert here.
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

    // The container binding is per-request in production but the test
    // process holds a single Application instance, so reset between
    // tests so a stale context from a previous test cannot leak.
    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    // Register an inline probe route. Two variants: one with the
    // middleware applied (the contract under test) and one without
    // (proves `pos_context()` is null when the middleware did not run).
    Route::middleware(['api', 'auth:sanctum', 'SetSessionDataApi'])
        ->prefix('api/v1/test-context')
        ->get('/probe', function () {
            return response()->json([
                'context' => pos_context(),
                'business_id' => pos_context('business.id'),
                'user_id' => pos_context('user.id'),
                'permissions' => pos_context('permissions', []),
                // R8.4 contract: the API path must NOT populate the
                // PHP session. These three keys mirror what the legacy
                // web `SetSessionData` middleware wrote, so an empty
                // value here is the strongest signal of compliance.
                'session_user' => session('user'),
                'session_business' => session('business'),
                'session_currency' => session('currency'),
            ]);
        });

    Route::middleware(['api', 'auth:sanctum'])
        ->prefix('api/v1/test-context')
        ->get('/no-middleware', function () {
            return response()->json([
                'context' => pos_context(),
                'business_id' => pos_context('business.id', 'absent'),
            ]);
        });

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

it('loads business and user context into pos.context without using session()', function () {
    // Authenticate via the existing login endpoint so we exercise the
    // same Sanctum stateful cookie flow the SPA uses.
    $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);

    $response = $this->getJson('/api/v1/test-context/probe');

    $response->assertStatus(200);

    // R8.5: the middleware populated `pos.context` and the helper read it.
    expect($response->json('user_id'))->toBe(test()->user->id);
    expect($response->json('business_id'))->toBe(test()->business->id);

    // Permissions are an array (empty here because no roles/permissions
    // are seeded; see file-level note about omitting Spatie tables).
    expect($response->json('permissions'))->toBeArray();

    // Full context shape: ensure the keys the design document promises
    // are present so consumers can rely on them.
    $context = $response->json('context');
    expect($context)->toBeArray()
        ->toHaveKeys(['user', 'business', 'currency', 'permissions', 'locations', 'financial_year']);
    expect($context['user']['username'] ?? null)->toBe('alice');
    expect($context['business']['name'] ?? null)->toBe('Acme POS');

    // R8.4: the API path must NOT touch the PHP session. None of the
    // legacy session keys should be populated by this request.
    expect($response->json('session_user'))->toBeNull();
    expect($response->json('session_business'))->toBeNull();
    expect($response->json('session_currency'))->toBeNull();
});

it('returns 401 for unauthenticated probe requests', function () {
    $response = $this->getJson('/api/v1/test-context/probe');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
});

it('exposes pos_context() as null when the middleware has not run', function () {
    // Authenticate so `auth:sanctum` accepts the request; the difference
    // versus the first test is that this route does NOT mount
    // `SetSessionDataApi`, so `pos.context` is never bound.
    $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);

    $response = $this->getJson('/api/v1/test-context/no-middleware');

    $response->assertStatus(200);
    expect($response->json('context'))->toBeNull();
    expect($response->json('business_id'))->toBe('absent');
});
