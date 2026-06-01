<?php

use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Property test: Sanctum cookie flow integrity (Pest, task 3.4)
|--------------------------------------------------------------------------
|
| Validates: R10.1, R10.2, R10.3, R10.4, R10.6.
| Property: P7 (Sanctum cookie flow integrity).
|
| Property statement (from design.md):
|
|   For any successful POST /api/v1/auth/login followed by an
|   authenticated GET /api/v1/auth/me from the same browser context,
|   the user identity returned by `me` SHALL equal the user identity
|   authenticated by `login`. After POST /api/v1/auth/logout, any
|   subsequent call to /api/v1/auth/me SHALL return 401 with
|   `code = "unauthenticated"`.
|
| Strategy
| --------
|
| The workspace ships `fakerphp/faker` but does not pull in
| `pest-plugin-faker`. Per the task spec we use
| `\Faker\Factory::create()->seed($i)` for deterministic reproduction
| without adding a new dependency.
|
| The test is a single Pest `it()` block that loops 150 iterations
| (above the spec's >=100 minimum). On every iteration we:
|
|   1. Generate a random valid user (random username/email/password) and
|      persist them with the same business_id seeded in beforeAll.
|
|   2. Issue POST /api/v1/auth/login. With probability 0.5 we use the
|      `username`, otherwise the `email` — exercising both branches of
|      the `Auth::attempt(['username' => ...])` and the
|      `Auth::attempt(['email' => ...])` fallback inside ApiAuthController
|      (R10.2 wording around "email and password").
|
|   3. Assert the login returns 200 and `data.user.id` equals the freshly
|      created user's id (R10.2).
|
|   4. Call /api/v1/auth/me 1..3 times (random) and assert every response
|      returns the same user id (R10.3). Multiple calls validate the
|      "browser context" durability: cookies set by login remain valid
|      across an arbitrary number of in-session reads.
|
|   5. POST /api/v1/auth/logout, expect 204 (R10.4).
|
|   6. Call /api/v1/auth/me again, expect 401 with body
|      `{"code": "unauthenticated"}` (R10.4 + R10.6 combined: only
|      Sanctum-managed identity is honored, no Passport bearer token
|      backdoor).
|
|   7. Reset auth state so the next iteration's request manager starts
|      with a clean slate. The Pest TestCase reuses the application
|      across calls within one `it()` block, which is what we want
|      during a single iteration (login -> me -> logout -> me must
|      share cookies) but is undesirable across iterations: leftover
|      guard state from iteration N would leak into N+1. In particular
|      Laravel's `Authenticate` middleware (the one behind
|      `auth:sanctum`) calls `$this->auth->shouldUse('sanctum')` on a
|      successful authentication, which permanently sets
|      `config('auth.defaults.guard')` to `'sanctum'` (a RequestGuard).
|      The next iteration's `Auth::attempt(...)` inside the controller
|      then resolves to that RequestGuard, which has no `attempt()`
|      method — yielding a 500 with "Method
|      Illuminate\Auth\RequestGuard::attempt does not exist". Resetting
|      `auth.defaults.guard` to 'web' AND calling `forgetGuards()`
|      between iterations fully un-leaks this.
|
| Iteration count rationale
| -------------------------
|
| 150 iterations is above the >=100 minimum and gives enough coverage
| of randomized request orderings and credential variations without
| being prohibitively slow. Each iteration is a 4-request round-trip
| (login + 1..3 `me` + logout + `me`) plus a User::create, so wall-
| clock cost is roughly linear in iteration count.
|
| Throttling
| ----------
|
| `routes/api.php` declares `throttle:300,1` at the route group level and
| the global `api` middleware group registers `throttle:api` (60 req/min
| keyed by IP for unauthenticated callers, per the Laravel default in
| `RouteServiceProvider`). The cache driver in `phpunit.xml` is `array`,
| so the throttle persists for the duration of the test process. Each
| iteration makes 4..6 requests (login + 1..3 `/me` + logout + `/me`),
| so 150 iterations is up to 900 requests — well above either limit.
|
| To avoid 429 false negatives we vary `REMOTE_ADDR` per iteration in the
| `10.{(i>>8)&0xff}.{i&0xff}.7` form, spreading throttle keys across 150
| distinct IPs. Within a single iteration all requests share the same
| `REMOTE_ADDR` so the throttle never trips on the in-iteration sequence
| (5..6 requests per IP). Cookies are session-scoped (no IP binding) so
| the in-iteration login/me/logout chain still shares the same session.
|
| Schema
| ------
|
| Mirrors `tests/Feature/Api/AuthFlowTest.php`: a SQLite-in-memory
| schema covering only `currencies`, `business`, `users`, and
| `personal_access_tokens` — just enough for `Auth::attempt`, the
| post-auth state checks, and Sanctum to function. The legacy MySQL
| migration suite is too large and uses MySQL-specific syntax to be
| usable here.
*/

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

    // Seed a single shared business + currency. Every property iteration
    // creates its own random user against this business.
    $currencyId = DB::table('currencies')->insertGetId([
        'country' => 'United States',
        'currency' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ]);

    $business = Business::create([
        'name' => 'Acme POS Property',
        'currency_id' => $currencyId,
        'time_zone' => 'UTC',
        'is_active' => true,
    ]);

    test()->businessId = $business->id;

    // Reset the auth manager so a previously cached `web` or `sanctum`
    // guard does not carry a stale identity from a prior test.
    auth()->forgetGuards();
});

it('preserves login -> me -> logout -> me identity across 150 random users', function () {
    $iterations = 150;

    for ($i = 0; $i < $iterations; $i++) {
        $faker = \Faker\Factory::create();
        $faker->seed($i);

        // Generate a unique-per-iteration username so we can re-run any
        // single seed in isolation; the "user_{$i}_" prefix guarantees
        // uniqueness even when Faker's underlying pool repeats.
        $username = 'user_'.$i.'_'.preg_replace('/[^a-zA-Z0-9_]/', '', $faker->userName());
        // Force email uniqueness for the same reason — even though the
        // schema has no UNIQUE on email, the login-by-email branch
        // matches by exact equality and would be ambiguous otherwise.
        $email = $i.'_'.$faker->safeEmail();
        $password = $faker->password(8, 30);
        $firstName = $faker->firstName();
        $lastName = $faker->lastName();
        $surname = $faker->randomElement(['Mr', 'Ms', 'Dr', null]);

        $user = User::create([
            'user_type' => 'user',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'surname' => $surname,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'business_id' => test()->businessId,
            'status' => 'active',
            'allow_login' => true,
            'language' => 'en',
        ]);

        // Fresh auth manager + headers for every iteration. The Pest
        // TestCase reuses the application across iterations, so we have
        // to clear cached guards explicitly so the previous iteration's
        // logged-out session can't leak into this one. We also reset
        // the default guard back to 'web' because the framework's
        // `Authenticate` middleware (behind `auth:sanctum`) calls
        // `$this->auth->shouldUse('sanctum')` on every successful
        // authentication, which mutates `config('auth.defaults.guard')`
        // permanently. Without this reset, iteration 1's
        // `Auth::attempt(...)` resolves to Sanctum's RequestGuard
        // (which has no `attempt()` method) and crashes with a 500.
        config()->set('auth.defaults.guard', 'web');
        auth()->forgetGuards();

        // Spread throttle keys across distinct IPs so the route-level
        // `throttle:300,1` and the global `throttle:api` never trip:
        // 150 iterations × ~5 requests = 750 requests, all 5 within an
        // iteration share the same IP (so cookies + session work) but
        // each iteration uses a fresh IP.
        $remoteAddr = '10.'.(($i >> 8) & 0xff).'.'.($i & 0xff).'.7';
        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr]);

        // R10.2: half the time log in by username, half by email. Both
        // branches must succeed for a freshly created active user.
        $loginField = $faker->boolean(50) ? $username : $email;

        $loginResponse = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:8000',
            'Referer' => 'http://127.0.0.1:8000/',
        ])->postJson('/api/v1/auth/login', [
            'login' => $loginField,
            'password' => $password,
            'device_name' => 'Pest Property Iter '.$i,
        ]);

        // Helpful diagnostic on failure: include the seed and the chosen
        // login field so any failing iteration is trivially reproducible.
        expect($loginResponse->getStatusCode())->toBe(
            200,
            "Iteration $i (seed=$i): login failed for login_field=$loginField, status="
                .$loginResponse->getStatusCode().', body='.$loginResponse->getContent()
        );

        $loginUserId = $loginResponse->json('data.user.id');
        expect($loginUserId)->toBe(
            $user->id,
            "Iteration $i (seed=$i): login returned user id $loginUserId but created $user->id"
        );

        // R10.3: /me returns the same identity on a freshly authenticated
        // browser context. Make 1..3 calls so we exercise the "stable
        // across multiple reads" sub-property explicitly. Each call must
        // return the same user id and username; if the cookie were not
        // round-tripping correctly we'd see a 401 here.
        $meCallsBeforeLogout = $faker->numberBetween(1, 3);
        for ($k = 0; $k < $meCallsBeforeLogout; $k++) {
            $meResponse = $this->withHeaders([
                'Origin' => 'http://127.0.0.1:8000',
            ])->getJson('/api/v1/auth/me');

            expect($meResponse->getStatusCode())->toBe(
                200,
                "Iteration $i (seed=$i): /me call #$k after login returned status "
                    .$meResponse->getStatusCode().', body='.$meResponse->getContent()
            );
            expect($meResponse->json('data.user.id'))->toBe(
                $user->id,
                "Iteration $i (seed=$i): /me returned different user id"
            );
            expect($meResponse->json('data.user.username'))->toBe(
                $username,
                "Iteration $i (seed=$i): /me returned different username"
            );
        }

        // R10.4: logout must succeed (204).
        $logoutResponse = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:8000',
        ])->postJson('/api/v1/auth/logout');

        expect($logoutResponse->getStatusCode())->toBe(
            204,
            "Iteration $i (seed=$i): logout returned status "
                .$logoutResponse->getStatusCode().', body='.$logoutResponse->getContent()
        );

        // R10.4 + R10.6: subsequent /me MUST be 401 with code unauthenticated.
        // R10.6 in particular forbids any Passport bearer-token backdoor:
        // since we never issued one, a 200 here would mean the framework
        // resurrected the previous identity from somewhere, which would
        // violate the property.
        $meAfterLogout = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:8000',
        ])->getJson('/api/v1/auth/me');

        expect($meAfterLogout->getStatusCode())->toBe(
            401,
            "Iteration $i (seed=$i): /me after logout returned status "
                .$meAfterLogout->getStatusCode().', body='.$meAfterLogout->getContent()
        );
        expect($meAfterLogout->json('code'))->toBe(
            'unauthenticated',
            "Iteration $i (seed=$i): /me after logout returned code='"
                .$meAfterLogout->json('code')."', expected 'unauthenticated'"
        );

        // Hygiene for the next iteration: drop the cached guard so the
        // freshly-deleted session can't be revived by a stale singleton,
        // and reset the default guard back to 'web' for the same reason
        // documented at the top of this loop body.
        config()->set('auth.defaults.guard', 'web');
        auth()->forgetGuards();
    }
});
