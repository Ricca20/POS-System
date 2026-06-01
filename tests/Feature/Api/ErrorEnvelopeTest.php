<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Canonical error envelope coverage (Pest) — task 2.4 / Property P3.
|--------------------------------------------------------------------------
|
| Validates: R8.2, R8.3, R10.4, R10.5.
|
| Every error path that can leave Laravel for an `/api/v1/*` request must:
|   1. Carry `Content-Type: application/json`.
|   2. Have a JSON-decodable body (`{ code, message, errors }`).
|   3. Never redirect (no 3xx; redirects under `/api/v1/*` are forbidden by
|      R8.3 and the central `Handler::render()` override coerces any that
|      slip through).
|
| Inline test routes are mounted under `/api/v1/test/*` inside the same
| `api` middleware group as production routes so they exercise the same
| exception pipeline. The dedicated property fuzz test for P3 lives in
| task 2.11 (`tests/Feature/Property/ApiJsonPurityPropertyTest.php`).
*/

/**
 * Strict CSRF middleware mirror of the one in AuthFlowTest. Duplicated
 * here under a different class name to avoid collisions with that file's
 * `TestStrictCsrfToken` when both suites load in the same Pest run.
 */
class ErrorEnvelopeStrictCsrfToken extends BaseVerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    // Mount inline test routes under /api/v1/test/* through the same `api`
    // middleware stack the production routes use, so the central
    // `App\Exceptions\Handler` renderables are the units under test.
    Route::middleware('api')->prefix('api/v1/test')->group(function () {
        Route::get('/throw-not-found', fn () => abort(404));

        Route::get('/throw-403', fn () => abort(403));

        Route::get('/throw-419', function () {
            throw new \Illuminate\Session\TokenMismatchException('CSRF token mismatch.');
        });

        Route::get('/throw-503', fn () => abort(503));

        Route::get('/throw-runtime', fn () => throw new \RuntimeException('boom'));

        Route::get(
            '/validate',
            fn (\Illuminate\Http\Request $r) => tap($r, fn ($r) => $r->validate(['x' => 'required']))->all()
        );
        Route::post(
            '/validate',
            fn (\Illuminate\Http\Request $r) => tap($r, fn ($r) => $r->validate(['x' => 'required']))->all()
        );

        Route::get('/redirect', fn () => redirect('/somewhere-else'));
    });

    // Refresh the route cache so the inline routes are registered fresh
    // every test (Laravel caches the route list on the application kernel).
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
});

/**
 * Helper: assert a response is JSON-shaped (Content-Type, parseable body)
 * and is not a redirect.
 */
function assertJsonEnvelope(\Illuminate\Testing\TestResponse $response): array
{
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    expect($response->isRedirection())->toBeFalse();

    $decoded = json_decode($response->getContent(), true);
    expect($decoded)->toBeArray();

    return $decoded;
}

it('returns the canonical 404 envelope for NotFoundHttpException under /api/v1/*', function () {
    $response = $this->getJson('/api/v1/test/throw-not-found');

    $response->assertStatus(404);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'not_found',
        'errors' => null,
    ]);
    expect($body['message'])->toBeString();
});

it('returns the canonical 403 envelope for HttpException(403) under /api/v1/*', function () {
    $response = $this->getJson('/api/v1/test/throw-403');

    $response->assertStatus(403);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'forbidden',
        'errors' => null,
    ]);
    expect($body['message'])->toBeString();
});

it('returns the canonical 419 envelope for TokenMismatchException under /api/v1/*', function () {
    $response = $this->getJson('/api/v1/test/throw-419');

    $response->assertStatus(419);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'csrf_mismatch',
        'errors' => null,
    ]);
});

it('returns the canonical 503 envelope for HttpException(503) under /api/v1/*', function () {
    $response = $this->getJson('/api/v1/test/throw-503');

    $response->assertStatus(503);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'offline_required',
        'errors' => null,
    ]);
});

it('returns the canonical 422 envelope for ValidationException under /api/v1/*', function () {
    // POST without the required `x` field — Laravel's validate() throws
    // ValidationException, which our Handler renderable converts to the
    // canonical envelope (R10.4).
    $response = $this->postJson('/api/v1/test/validate', []);

    $response->assertStatus(422);
    $body = assertJsonEnvelope($response);

    expect($body['code'])->toBe('validation_failed');
    expect($body['errors'])->toBeArray()->toHaveKey('x');
    expect($body['errors']['x'])->toBeArray();
});

it('returns the canonical 500 envelope for unexpected Throwable under /api/v1/*', function () {
    // APP_DEBUG=true (the default in phpunit.xml) lets Laravel render Whoops
    // HTML for unhandled exceptions, which would defeat the catch-all
    // renderable. Force the production exception path for this test only.
    config()->set('app.debug', false);

    $response = $this->getJson('/api/v1/test/throw-runtime');

    $response->assertStatus(500);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'server_error',
        'errors' => null,
    ]);
    expect($body['message'])->toBeString();
});

it('coerces a 302 redirect under /api/v1/* to the unauthenticated envelope (R8.3)', function () {
    $response = $this->getJson('/api/v1/test/redirect');

    // R8.3: paths under /api/v1/* must never redirect; the central
    // exception handler / response coercion converts any 3xx into the
    // canonical 401 envelope so the SPA never has to follow Location.
    expect($response->isRedirection())->toBeFalse();
    $response->assertStatus(401);
    $body = assertJsonEnvelope($response);

    expect($body)->toMatchArray([
        'code' => 'unauthenticated',
        'errors' => null,
    ]);
});

it('honours FormRequest custom validation responses (no double-wrapping)', function () {
    // LoginRequest::failedValidation() throws HttpResponseException with a
    // pre-built JsonError envelope. The Handler's ValidationException
    // renderable returns null when `$e->response` is set, so the
    // FormRequest envelope must pass through unchanged.
    $response = $this->postJson('/api/v1/auth/login', []);

    $response->assertStatus(422);
    $body = assertJsonEnvelope($response);

    expect($body['code'])->toBe('validation_failed');
    expect($body['errors'])->toBeArray();
});

it('does not regress on web routes — HTML 404 still rendered for non-API paths', function () {
    // The renderables short-circuit (return null) when isApiRequest() is
    // false, so Symfony's HTML 404 page should still be served for web
    // paths. We don't assert the body shape, only that the request is not
    // forced into a JSON envelope.
    $response = $this->call('GET', '/no-such-web-path-' . uniqid());

    expect($response->getStatusCode())->toBe(404);
    // Without `Accept: application/json`, the response should NOT be JSON.
    // (Laravel's exception page is HTML in debug mode.)
    expect($response->headers->get('Content-Type'))
        ->not->toStartWith('application/json');
});
