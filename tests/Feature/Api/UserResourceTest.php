<?php

use App\Business;
use App\Http\Resources\UserResource;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `UserResource` snapshot test (Pest, task 3.3)
|--------------------------------------------------------------------------
|
| Validates: R10.2, R10.3.
|
| Asserts the JSON shape produced by `App\Http\Resources\UserResource`:
|   1. The expected key set is present.
|   2. Sensitive columns (`password`, `remember_token`, `api_token`,
|      `bank_details`) are NEVER present.
|   3. `permissions` degrades to `[]` when the Spatie tables are absent
|      (the resource wraps `getAllPermissions()` in try/catch the same
|      way `SetSessionDataApi` does).
|   4. `allow_login` is a strict PHP boolean, not 0/1, so the SPA can
|      use it directly in conditionals.
|   5. End-to-end: `POST /api/v1/auth/login` returns the same shape under
|      `data.user` and never leaks the sensitive keys through the
|      controller envelope either.
|
| The schema we stand up here mirrors `BusinessSettingsTest`'s minimal
| `business / users / currencies / personal_access_tokens` set. We
| deliberately do NOT create the spatie tables, so the resource's
| try/catch fallback to `[]` gets exercised (assertion 3). The auth
| middleware tolerates the missing tables the same way.
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
        'language' => 'en',
    ]);

    test()->user = $user;
    test()->business = $business;

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    // Eloquent caches the resolved column list for `isGuarded()` in a
    // private static keyed by class name. A previous test in the same
    // Pest run (e.g. AuthFlowTest) booted `App\User` against a different
    // SQLite schema, which pinned that cache. Reset it via reflection
    // so this test's columns are honoured during `User::create()`.
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

it('exposes only the documented safe key set', function () {
    $payload = (new UserResource(test()->user))->toArray(request());

    // Assertion 1: every documented field is present so SPA forms never
    // see `undefined` for a known key.
    $expectedKeys = [
        'id',
        'username',
        'email',
        'first_name',
        'last_name',
        'surname',
        'language',
        'user_type',
        'business_id',
        'status',
        'allow_login',
        'created_at',
        'updated_at',
        'permissions',
    ];

    foreach ($expectedKeys as $key) {
        expect($payload)->toHaveKey($key);
    }

    // Field values reflect the seeded user.
    expect($payload['id'])->toBe(test()->user->id);
    expect($payload['username'])->toBe('alice');
    expect($payload['email'])->toBe('alice@example.com');
    expect($payload['first_name'])->toBe('Alice');
    expect($payload['last_name'])->toBe('Cashier');
    expect($payload['surname'])->toBe('Mr');
    expect($payload['language'])->toBe('en');
    expect($payload['user_type'])->toBe('user');
    expect($payload['business_id'])->toBe(test()->business->id);
    expect($payload['status'])->toBe('active');
});

it('omits every sensitive credential or legacy column from the JSON shape', function () {
    $payload = (new UserResource(test()->user))->toArray(request());

    // Hard-coded denylist of every known leak vector. If a future change
    // accidentally widens the resource (e.g. `parent::toArray()` instead
    // of explicit fields), this assertion fails loudly.
    $forbidden = [
        'password',
        'remember_token',
        'api_token',
        'bank_details',
        'dob',
        'essentials_pay',
        'essentials_salary',
    ];

    foreach ($forbidden as $key) {
        expect($payload)->not->toHaveKey($key);
    }

    // Total key count should equal the documented set; this prevents
    // accidental additions from leaking into the contract without
    // updating the snapshot test.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'id',
        'username',
        'email',
        'first_name',
        'last_name',
        'surname',
        'language',
        'user_type',
        'business_id',
        'status',
        'allow_login',
        'created_at',
        'updated_at',
        'permissions',
    ]);
});

it('returns an empty permissions array when the Spatie tables are absent', function () {
    // No spatie tables exist in this test's schema. The resource MUST
    // catch the underlying SQL error and fall back to `[]` so request
    // handling never crashes on environments without the full migration
    // set.
    $payload = (new UserResource(test()->user))->toArray(request());

    expect($payload['permissions'])->toBeArray()->toBe([]);
});

it('returns allow_login as a strict boolean, not 0 or 1', function () {
    $payload = (new UserResource(test()->user))->toArray(request());

    // SQLite stores booleans as integers; the resource must coerce so
    // the SPA can use the value directly in template conditionals.
    expect($payload['allow_login'])->toBeBool();
    expect($payload['allow_login'])->toBe(true);
});

it('returns matching shape over /api/v1/auth/login without leaking sensitive keys', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ]);

    $response->assertStatus(200);

    // The login envelope wraps the resource under `data.user`. Pull it
    // out and assert against the same key set as the unit-style test
    // above so any divergence between controller-built shape and the
    // resource is caught here.
    $userPayload = $response->json('data.user');

    expect($userPayload)->toBeArray();
    expect(array_keys($userPayload))->toEqualCanonicalizing([
        'id',
        'username',
        'email',
        'first_name',
        'last_name',
        'surname',
        'language',
        'user_type',
        'business_id',
        'status',
        'allow_login',
        'created_at',
        'updated_at',
        'permissions',
    ]);

    foreach (['password', 'remember_token', 'api_token', 'bank_details'] as $forbidden) {
        expect($userPayload)->not->toHaveKey($forbidden);
    }

    expect($userPayload['id'])->toBe(test()->user->id);
    expect($userPayload['username'])->toBe('alice');
    expect($userPayload['allow_login'])->toBeBool()->toBe(true);
    expect($userPayload['permissions'])->toBeArray();
});
