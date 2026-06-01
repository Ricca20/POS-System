<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Property test: API JSON purity (Pest, task 2.11)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2, R8.3, R8.4.
| Property: P3 (API JSON purity).
|
| Property statement (from design.md):
|
|   For any request to a path matching `/api/v1/*`, the response
|   `Content-Type` SHALL be `application/json` (or
|   `application/problem+json`), and the response body SHALL be
|   parseable JSON. No path under `/api/v1/*` SHALL return Blade-
|   rendered HTML or a redirect to a Blade view.
|
| Strategy
| --------
|
| The workspace ships `fakerphp/faker` but does not pull in
| `pest-plugin-faker`. Per the task spec we use
| `\Faker\Factory::create()->seed($i)` for deterministic reproduction
| without adding a new dependency. The test is a single Pest `it()`
| block that loops 200 iterations (above the spec's >=100 minimum).
|
| Each iteration:
|
|   1. Picks an HTTP verb at random from
|      {GET, POST, PUT, PATCH, DELETE}. OPTIONS is intentionally
|      excluded — Laravel's CORS preflight returns 204 with no
|      Content-Type, which is a legitimate edge that the SPA never
|      actually issues directly (browsers issue OPTIONS, and they're
|      handled by the framework's CORS middleware).
|
|   2. Picks either (a) a real `/api/v1/*` URI from
|      `Route::getRoutes()`, with `{...}` parameters substituted by
|      random integers, or (b) a synthesised path like
|      `/api/v1/{seg}/{seg}` that almost certainly doesn't exist —
|      forcing the `NotFoundHttpException` path through the JSON
|      error envelope (R8.1, R8.2). Each branch is taken ~50% of the
|      time so both real-route and 404 paths are exercised heavily.
|
|   3. Generates a random JSON body (random keys, random word/int
|      values) ~70% of the time, empty otherwise. Bodies that don't
|      satisfy the endpoint's FormRequest produce 422 envelopes;
|      bodies that don't apply to the verb (GET) are simply ignored
|      by Laravel.
|
|   4. Generates a random header set: `Accept: application/json`
|      always (so the SPA contract is exercised), random
|      Origin/Referer to engage Sanctum stateful, deliberate bogus
|      `X-XSRF-TOKEN` and explicit `Content-Type: application/json`
|      half the time.
|
|   5. Issues the request via `$this->call()` so we can pass server
|      variables (notably `REMOTE_ADDR`, see below) directly. The
|      test does NOT use `getJson()` / `postJson()` because those
|      helpers force their own header envelope.
|
| Per-iteration REMOTE_ADDR
| -------------------------
|
| `routes/api.php` declares `throttle:300,1` at the route group level
| but the global `api` middleware group registers `throttle:api`,
| which is configured in `App\Providers\RouteServiceProvider` to
| 60 req/min keyed by IP for unauthenticated callers. The cache
| driver in `phpunit.xml` is `array`, so the throttle persists for
| the duration of the test process. Without intervention we'd hit
| HTTP 429 around iteration 60 and every subsequent iteration would
| return the same throttle envelope — still JSON, but it'd dilute
| the property's effective coverage (we want to actually reach the
| handlers).
|
| Each iteration therefore varies `REMOTE_ADDR` in the
| `10.{(i>>8)&0xff}.{i&0xff}.7` form, spreading the throttle key
| space across 200 distinct IPs. This is purely a coverage knob; the
| property assertion (JSON purity) holds whether or not the throttle
| trips.
|
| What is asserted, per iteration
| -------------------------------
|
|   - `Content-Type` starts with `application/json` (R8.2).
|   - Response is not 3xx (R8.3 — no redirects under /api/v1/*).
|   - Body, when non-empty, is parseable JSON (R8.2).
|   - Body never contains `<!DOCTYPE html>` (R8.3, R8.4 — no Blade
|     escapes).
|
| 204/304 with empty body is theoretically possible if a route
| short-circuits with no content; in those cases the JSON-parse
| assertion is skipped. None of the currently registered
| `/api/v1/*` routes return 204 along an unauthenticated path
| (logout's 204 is gated by `auth:sanctum` and the property test
| never authenticates), so this is just defensive.
|
| Reproducing a failure
| ---------------------
|
| Each error message embeds the iteration index (which equals the
| Faker seed). To reproduce a failing iteration N, change the loop
| to `for ($i = N; $i < N + 1; $i++)` and re-run the file.
*/

it('every /api/v1/* response is JSON, never redirects, and never returns Blade HTML, across 200 random iterations', function () {
    $iterations = 200;
    $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    $synthSegments = [
        'foo', 'bar', 'baz',
        'health', 'auth', 'me', 'config', 'logout', 'login',
        'modules', 'sample-module', 'unknown',
    ];
    $bodyKeys = [
        'login', 'password', 'device_name',
        'email', 'username',
        'foo', 'bar', 'x', 'y',
    ];

    // Enumerate every /api/v1/* route once. The route table doesn't
    // change during this test, so a single snapshot is fine.
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
        ->values()
        ->all();

    // Sanity: we must have actual routes to fuzz against, otherwise the
    // property test would silently degenerate to all-synthesised paths.
    expect($apiRoutes)->not->toBeEmpty();

    for ($i = 0; $i < $iterations; $i++) {
        $faker = \Faker\Factory::create();
        $faker->seed($i);

        // 50% real route URI, 50% synthesised path. The 50/50 split
        // exercises both the live handlers (real-route branch) and
        // the canonical 404 envelope (synthesised branch) heavily.
        if ($faker->boolean(50)) {
            $route = $faker->randomElement($apiRoutes);
            $uri = $route->uri();

            // Substitute `{param}` placeholders with random integers.
            // Substituted values that don't resolve to a real model
            // simply produce a 404 from the route's action — still
            // JSON via the central exception handler.
            $uri = preg_replace_callback(
                '/\{[^}]+\}/',
                fn () => (string) $faker->numberBetween(1, 99999),
                $uri
            );

            $path = '/'.$uri;
        } else {
            $segCount = $faker->numberBetween(1, 4);
            $segs = [];
            for ($s = 0; $s < $segCount; $s++) {
                $segs[] = $faker->randomElement($synthSegments);
            }
            $path = '/api/v1/'.implode('/', $segs);
        }

        $method = $faker->randomElement($methods);

        // Random body: ~70% non-empty. The body is JSON-encoded
        // unconditionally so that any FormRequest seeing
        // `Content-Type: application/json` parses it the same way it
        // would for a real SPA call.
        $body = [];
        if ($faker->boolean(70)) {
            $bodyKeyCount = $faker->numberBetween(1, 4);
            $chosenKeys = $faker->randomElements($bodyKeys, $bodyKeyCount);
            foreach ($chosenKeys as $k) {
                $body[$k] = $faker->boolean(50) ? $faker->word() : $faker->numberBetween(0, 1000);
            }
        }

        // Header set. `Accept: application/json` is always present so
        // the SPA contract path is what's actually under test.
        $headers = ['Accept' => 'application/json'];
        if ($faker->boolean(50)) {
            $headers['Origin'] = 'http://127.0.0.1:8000';
            $headers['Referer'] = 'http://127.0.0.1:8000/';
        }
        if ($faker->boolean(20)) {
            $headers['X-XSRF-TOKEN'] = 'invalid-csrf';
        }
        if ($faker->boolean(20)) {
            $headers['Content-Type'] = 'application/json';
        }

        // Build the server-vars array. Each header is mapped to its
        // PHP CGI form (`HTTP_*`, hyphens to underscores). REMOTE_ADDR
        // is varied per iteration to spread the throttle key space.
        $serverVars = [
            'REMOTE_ADDR' => '10.'.(($i >> 8) & 0xff).'.'.($i & 0xff).'.7',
            'CONTENT_TYPE' => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $serverVars['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = $this->call(
            $method,
            $path,
            [],         // parameters
            [],         // cookies
            [],         // files
            $serverVars,
            json_encode($body)
        );

        $status = $response->getStatusCode();
        $contentType = $response->headers->get('Content-Type');
        $content = $response->getContent();

        // ----- Property assertions (R8.2, R8.3, R8.4 → P3) -----

        // R8.3: paths under /api/v1/* SHALL NOT issue redirects. Any
        // 3xx slipping through indicates `Handler::render()` and
        // `ForceApiJsonResponse` both failed to coerce it.
        expect($response->isRedirection())->toBeFalse(
            "Iteration $i (seed=$i): $method $path returned 3xx (status $status, Location='".
            ($response->headers->get('Location') ?? '').
            "'); body='".substr((string) $content, 0, 200)."'"
        );

        // R8.2: Content-Type SHALL start with `application/json`. The
        // OR `application/problem+json` clause from the spec is a
        // superset (also starts with `application/`), so we test the
        // narrower invariant that's actually emitted by `JsonError`.
        expect($contentType)->toStartWith(
            'application/json',
            "Iteration $i (seed=$i): $method $path produced Content-Type '$contentType' ".
            "(status $status); body='".substr((string) $content, 0, 200)."'"
        );

        // R8.2: response body SHALL be parseable JSON. Empty body is
        // tolerated only for status codes that are explicitly defined
        // by RFC 7230 to omit a body (204, 304); for every other
        // status the body must decode to a non-null JSON value.
        if ($content !== '' && $content !== null) {
            $decoded = json_decode($content, true);
            expect(json_last_error())->toBe(
                JSON_ERROR_NONE,
                "Iteration $i (seed=$i): $method $path body is not parseable JSON ".
                '(json_last_error_msg='.json_last_error_msg().
                "); body='".substr((string) $content, 0, 200)."'"
            );
            expect($decoded)->not->toBeNull(
                "Iteration $i (seed=$i): $method $path decoded JSON is null; ".
                "body='".substr((string) $content, 0, 200)."'"
            );
        } else {
            // Empty body is only acceptable for 204/304. Anything else
            // with an empty body would mean the controller returned a
            // bare Response without a JSON envelope, which violates
            // R8.2.
            expect(in_array($status, [204, 304], true))->toBeTrue(
                "Iteration $i (seed=$i): $method $path returned empty body with ".
                "status $status (expected 204 or 304 for empty bodies)"
            );
        }

        // R8.3 / R8.4: response body SHALL NOT contain Blade HTML.
        // Case-insensitive match catches both `<!DOCTYPE html>` and
        // `<!doctype html>` variants. The check uses `toContain` so
        // the assertion message includes a snippet of the offending
        // body, but we lower-case both sides to make it
        // case-insensitive in practice.
        expect(strtolower((string) $content))->not->toContain(
            '<!doctype html>',
            "Iteration $i (seed=$i): $method $path returned HTML body ".
            "(status $status, Content-Type '$contentType'); ".
            "body='".substr((string) $content, 0, 200)."'"
        );
    }
});
