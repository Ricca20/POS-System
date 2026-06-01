<?php

use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| `pos_boot()` no-op stub (Pest)
|--------------------------------------------------------------------------
|
| Validates: R7.3 — `pos_boot()` is replaced with a no-op stub that always
| returns "valid" so no Laravel code path performs an external license
| HTTP call.
|
| The legacy implementation called `curl_init()` directly (not Laravel's
| HTTP client), so `Http::fake()` alone cannot prove the absence of HTTP.
| We assert two complementary properties:
|
|   1. The function returns null for every legacy call signature, so the
|      `! empty($return)` check in `InstallController` passes through to
|      "success" without redirecting.
|   2. `Http::assertNothingSent()` confirms no Laravel HTTP client request
|      was issued — a defence-in-depth signal that the stub is pure even
|      if a future caller migrates to the Laravel HTTP facade.
|
| The original `curl_init()` calls cannot be mocked without an extension,
| but the body of the function has been replaced wholesale (see
| `app/Http/helpers.php`), so a static read of the source is the canonical
| guarantee. We add a third assertion that scans the function source for
| any `curl_*` references — if they reappear, this test fails.
*/

beforeEach(function () {
    Http::fake();
});

it('returns null for the canonical legacy call signature', function () {
    $result = pos_boot(
        'http://example.test',     // $ul
        '/var/www/desktop',         // $pt
        'TEST-LICENSE-CODE',        // $lc
        'user@example.test',        // $em
        'envato_username',          // $un
    );

    expect($result)->toBeNull();
});

it('returns null when invoked with the type=2 verification mode', function () {
    $result = pos_boot(
        'http://example.test',
        '/var/www/desktop',
        'TEST-LICENSE-CODE',
        'user@example.test',
        'envato_username',
        2,
    );

    expect($result)->toBeNull();
});

it('returns null when invoked with no arguments at all', function () {
    expect(pos_boot())->toBeNull();
});

it('does not issue any Laravel HTTP client request', function () {
    pos_boot(
        'http://example.test',
        '/var/www/desktop',
        'TEST-LICENSE-CODE',
        'user@example.test',
        'envato_username',
    );

    Http::assertNothingSent();
});

it('contains no curl_* calls in the function source', function () {
    // Static guarantee: the helpers file must not reintroduce curl
    // anywhere inside the `pos_boot` definition. Read the source and
    // assert the body — between the `function pos_boot(...)` opening
    // and the next top-level `}` — contains zero `curl_` tokens.
    $source = file_get_contents(base_path('app/Http/helpers.php'));

    // Slice from `function pos_boot` to the next `if (! function_exists`,
    // which marks the start of the next helper. This isolates only the
    // `pos_boot` body for inspection.
    $start = strpos($source, 'function pos_boot');
    expect($start)->not->toBeFalse();

    $end = strpos($source, "if (! function_exists('humanFilesize'", $start);
    expect($end)->not->toBeFalse();

    $body = substr($source, $start, $end - $start);

    expect($body)->not->toMatch('/\bcurl_/');
});
