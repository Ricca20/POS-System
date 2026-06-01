<?php

use App\Business;
use App\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| API auth flow (Pest) — covers Property P7 (Sanctum cookie flow integrity).
|--------------------------------------------------------------------------
|
| Validates: R10.1, R10.2, R10.3, R10.4, R10.5, R10.6.
|
| Asserts the happy-path login -> me -> logout sequence, the CSRF mismatch
| envelope (R10.5), and the 422 envelope on bad credentials (R10.2).
|
| The dedicated property test for P7 lives in task 3.4
| (`tests/Feature/Property/SanctumCookieFlowPropertyTest.php`).
|
| The legacy MySQL migration suite is too large and uses MySQL-specific
| syntax, so this test stands up a minimal SQLite-in-memory schema covering
| only `currencies`, `business`, `users`, and `personal_access_tokens` —
| just enough for `Auth::attempt`, the post-auth state checks, and Sanctum
| to function.
*/

/**
 * Test variant of the CSRF middleware that does NOT bypass verification
 * when running unit tests. Bound for the test that asserts a missing CSRF
 * token returns 419.
 */
class TestStrictCsrfToken extends BaseVerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    // Route every Eloquent connection to a per-test SQLite memory database.
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    // The User model and others reference the `mysql` connection only via
    // the default; Business is connection-agnostic. Override the `mysql`
    // entry to also point at sqlite so any explicit `on('mysql')` works.
    config()->set('database.connections.mysql', config('database.connections.sqlite'));

    // Use a real (in-memory) session store so `Auth::attempt` and
    // `regenerate` work; Sanctum's stateful pipeline uses this when the
    // request matches a configured stateful domain.
    config()->set('session.driver', 'array');
    config()->set('sanctum.stateful', ['127.0.0.1:8000', 'localhost:8000']);

    DB::purge('sqlite');
    DB::purge('mysql');

    // Minimal schema: only the columns the auth flow actually touches.
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

    // Seed a single business + user. The Currency model declares no
    // `$fillable`, so seed it via the query builder; Business and User both
    // permit mass assignment of these fields.
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

    // Reset the auth manager so the previously cached `web` and `sanctum`
    // guards do not hold onto a session bound to the prior request.
    auth()->forgetGuards();

    // Every request flows through Sanctum stateful only when the Origin
    // matches a configured stateful domain; default these headers so each
    // test exercises the cookie-based auth path the SPA actually uses.
    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

it('logs in with valid credentials and returns the LoginResponse envelope', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ]);

    $response->assertStatus(200);

    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'user' => ['id', 'username', 'email', 'business_id', 'permissions'],
            'business' => ['id', 'name', 'is_active'],
            'permissions',
            'locations',
            'currency',
            'modules_enabled',
        ],
    ]);

    $response->assertJsonPath('data.user.id', test()->user->id);
    $response->assertJsonPath('data.user.username', 'alice');
    $response->assertJsonPath('data.business.id', test()->business->id);
    $response->assertJsonPath('data.business.is_active', true);

    // The UserResource (task 3.3) must never leak credential material or
    // legacy banking metadata into the SPA. Tightly assert each sensitive
    // key is absent from the `data.user` object.
    $userPayload = $response->json('data.user');
    expect($userPayload)->not->toHaveKey('password');
    expect($userPayload)->not->toHaveKey('remember_token');
    expect($userPayload)->not->toHaveKey('api_token');
    expect($userPayload)->not->toHaveKey('bank_details');

    // `permissions` is always present as an array; a fresh user with no
    // role assignments yields `[]` rather than `null` so the SPA can call
    // `.includes(...)` without a null guard.
    expect($response->json('data.user.permissions'))->toBeArray();
});

it('returns the same authenticated user from /auth/me after login', function () {
    $login = $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);

    $loginUserId = $login->json('data.user.id');

    $me = $this->getJson('/api/v1/auth/me')->assertStatus(200);

    expect($me->json('data.user.id'))->toBe($loginUserId);
    $me->assertJsonPath('data.user.username', 'alice');
});

it('invalidates the session on logout and returns 401 on subsequent /me', function () {
    $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);

    // Sanity check: cookie-based session is established before logout.
    $this->getJson('/api/v1/auth/me')->assertStatus(200);

    $logout = $this->postJson('/api/v1/auth/logout');
    $logout->assertStatus(204);

    $me = $this->getJson('/api/v1/auth/me');
    $me->assertStatus(401);
    $me->assertJsonPath('code', 'unauthenticated');
});

it('rejects login with invalid credentials using the validation_failed envelope', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'wrong-password',
        'device_name' => 'Pest Suite',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['login'],
    ]);
});

it('returns 419 for a stateful POST without a CSRF token', function () {
    // Force CSRF enforcement during this test by swapping in a strict
    // subclass that does not bypass on `runningInConsole && runningUnitTests`.
    app()->bind(BaseVerifyCsrfToken::class, TestStrictCsrfToken::class);
    config()->set('sanctum.middleware.verify_csrf_token', TestStrictCsrfToken::class);

    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ]);

    expect($response->getStatusCode())->toBe(419);
});
