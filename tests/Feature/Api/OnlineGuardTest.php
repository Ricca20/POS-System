<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OnlineGuard middleware (Pest)
|--------------------------------------------------------------------------
|
| Validates: R12.2, R12.3.
|
| The `online` middleware alias (see `App\Http\Kernel`) wires up the
| `App\Http\Middleware\OnlineGuard` class which uses
| `App\Services\ConnectivityProbe` to decide whether to short-circuit a
| request with the canonical 503 `offline_required` envelope.
|
| These tests cover the four reachability outcomes the probe must handle —
| success, server error, connection timeout, DNS failure — and the
| caching contract that lets bursts of guarded requests share a single
| outbound HEAD probe.
|
| The probe URL is configurable via `config('desktop.connectivity_probe_url')`
| (see `config/desktop.php`). We override it per test to a sentinel URL so
| `Http::fake()`'s wildcard matching is unambiguous regardless of the
| deployment default.
*/

beforeEach(function () {
    // In-memory cache so probe TTL does not leak between tests and so we
    // never touch the file cache. Reset the binding so the new config
    // takes effect immediately.
    config()->set('cache.default', 'array');
    Cache::flush();

    // Pin a deterministic probe URL so the `Http::fake()` URL pattern is
    // identical across tests; the production default still flows through
    // `config()` so this only narrows what we assert against.
    config()->set('desktop.connectivity_probe_url', 'https://probe.test/generate_204');
    config()->set('desktop.connectivity_probe_timeout_seconds', 3);
    config()->set('desktop.connectivity_probe_cache_ttl_seconds', 30);

    // Inline test routes so we don't have to add anything to the real
    // route file. The `api` group is required for Sanctum / throttling
    // to be wired up the same way production routes are.
    Route::middleware(['api', 'online'])
        ->prefix('api/v1/test-online')
        ->group(function () {
            Route::get('/probe', fn () => response()->json(['ok' => true]));
        });
});

it('lets the request through when the probe returns 204', function () {
    Http::fake([
        '*generate_204' => Http::response('', 204),
    ]);

    $response = $this->getJson('/api/v1/test-online/probe');

    $response->assertStatus(200);
    $response->assertExactJson(['ok' => true]);
});

it('returns the canonical 503 envelope when the probe returns a 5xx', function () {
    Http::fake([
        '*generate_204' => Http::response('', 503),
    ]);

    $response = $this->getJson('/api/v1/test-online/probe');

    $response->assertStatus(503);
    expect($response->headers->get('Content-Type'))
        ->toStartWith('application/json');
    $response->assertExactJson([
        'code' => 'offline_required',
        'message' => 'This feature requires an internet connection.',
        'errors' => null,
    ]);
});

it('treats a connection timeout as offline', function () {
    // Simulate a request timeout: the Laravel HTTP client surfaces these
    // as `ConnectionException`. The probe wraps the call in `try/catch
    // (\Throwable)` so the middleware should fall through to a 503.
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $response = $this->getJson('/api/v1/test-online/probe');

    $response->assertStatus(503);
    $response->assertJsonPath('code', 'offline_required');
});

it('treats a DNS resolution failure as offline', function () {
    // DNS failures bubble up as a generic Exception from Guzzle (cURL 6).
    // The probe must catch `\Throwable`, not just `Exception`, but a plain
    // Exception is the realistic shape here.
    Http::fake(function () {
        throw new \Exception('cURL error 6: Could not resolve host: probe.test');
    });

    $response = $this->getJson('/api/v1/test-online/probe');

    $response->assertStatus(503);
    $response->assertJsonPath('code', 'offline_required');
});

it('caches the probe result so a burst of requests issues only one HEAD', function () {
    Http::fake([
        '*generate_204' => Http::response('', 204),
    ]);

    // Two guarded requests in quick succession — the second must reuse
    // the cached probe result rather than issuing a fresh HEAD.
    $this->getJson('/api/v1/test-online/probe')->assertStatus(200);
    $this->getJson('/api/v1/test-online/probe')->assertStatus(200);

    Http::assertSentCount(1);
});
