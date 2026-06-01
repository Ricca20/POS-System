<?php

namespace App\Exceptions;

use App\Http\Responses\JsonError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Central exception handler.
 *
 * For any request matching `/api/v1/*` (or that explicitly accepts JSON),
 * every thrown exception is funneled through `App\Http\Responses\JsonError`
 * so the SPA only ever sees the canonical error envelope and never an HTML
 * Symfony page or a 302 redirect (R8.2, R8.3, R10.4, R10.5; Property P3).
 *
 * Renderables are registered in specific → general order: NotFound, Auth,
 * Validation, TokenMismatch, HttpException (covers explicit `abort()`
 * statuses including 503 from Online_Guard in task 2.8), and finally a
 * `\Throwable` catch-all that maps to a 500 `server_error`. In Laravel 9
 * the LAST matching renderable wins, so the catch-all must be registered
 * last; otherwise it would shadow every more specific handler.
 *
 * Web routes are unaffected: when `isApiRequest()` returns false, every
 * renderable returns `null` and Laravel falls back to its default HTML
 * error pages.
 */
class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // 404 — missing routes under /api/v1/* must return canonical JSON,
        // never the Symfony HTML 404 page (R8.1, R8.2).
        $this->renderable(function (NotFoundHttpException $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            return JsonError::notFound();
        });

        // 401 — Laravel's default unauthenticated() returns a redirect to
        // the login route when JSON is not detected, which would violate
        // R8.3. Force JSON for /api/v1/* (R10.4).
        $this->renderable(function (AuthenticationException $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            return JsonError::unauthenticated();
        });

        // 422 — most validation errors arrive here.
        //
        // FormRequests are allowed to override `failedValidation()` and
        // throw an HttpResponseException carrying their own JSON envelope
        // (LoginRequest does this, see app/Http/Requests/Api/LoginRequest.php).
        // Laravel's HttpResponseException short-circuits the pipeline before
        // ValidationException is ever raised, so this handler does not need
        // a special case for that scenario.
        //
        // ValidationException itself, however, also exposes a pre-built
        // `$e->response` when callers throw `ValidationException::withMessages()`
        // and explicitly attach a response. Respect that by returning null
        // (let the existing response flow through unchanged).
        $this->renderable(function (ValidationException $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            if ($e->response !== null) {
                return null;
            }

            return JsonError::validationFailed($e->errors());
        });

        // 419 — direct CSRF mismatches. In Sanctum stateful mode the
        // `EnsureFrontendRequestsAreStateful` middleware short-circuits with
        // its own 419 response before this renderable is reached, so this
        // is the safety net for any code path that throws
        // TokenMismatchException directly.
        $this->renderable(function (TokenMismatchException $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            return JsonError::csrfMismatch();
        });

        // Generic HttpException — covers explicit `abort(503, ...)`,
        // `abort(403)`, etc., plus any framework path that throws an
        // HttpException with a status code not handled by the more
        // specific renderables above.
        //
        // Note: NotFoundHttpException, AuthenticationException, and
        // TokenMismatchException are already handled above; their
        // renderables return a response and are matched first because
        // they target a more specific exception class. This handler is
        // for any HttpException that is not one of those subclasses.
        $this->renderable(function (HttpException $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = $e->getMessage();

            return match ($status) {
                404 => JsonError::notFound($message !== '' ? $message : 'The requested resource was not found.'),
                401 => JsonError::unauthenticated($message !== '' ? $message : 'Authentication is required to access this resource.'),
                403 => JsonError::forbidden('forbidden', $message !== '' ? $message : 'You do not have permission to perform this action.'),
                419 => JsonError::csrfMismatch($message !== '' ? $message : 'CSRF token mismatch.'),
                503 => JsonError::offlineRequired($message !== '' ? $message : 'This feature requires an internet connection.'),
                default => JsonError::make(
                    $status,
                    'http_'.$status,
                    $message !== '' ? $message : 'HTTP error.',
                ),
            };
        });

        // Generic catch-all. MUST be registered last: in Laravel 9 the last
        // matching renderable wins, so registering this earlier would shadow
        // every more specific handler above. The default Handler::report()
        // path still runs, so a 500 generated here is logged like any other
        // unhandled exception.
        $this->renderable(function (Throwable $e, $request) {
            if (! $this->isApiRequest($request)) {
                return null;
            }

            // HttpException is already handled by the dedicated renderable
            // above; let it pass through so we don't downgrade explicit
            // `abort()` statuses to 500.
            if ($e instanceof HttpException) {
                return null;
            }

            // FormRequests and similar code paths throw HttpResponseException
            // to short-circuit with a fully-formed Response (e.g. LoginRequest
            // returning its own 422 envelope). Don't replace those.
            if ($e instanceof HttpResponseException) {
                return null;
            }

            return JsonError::serverError();
        });
    }

    /**
     * Override `render` so that even if a default Laravel code path emits a
     * 3xx response for an `/api/v1/*` request (e.g. `redirectTo()` for
     * unauthenticated users hitting a `redirect:home` middleware), it gets
     * coerced into a JSON envelope. R8.3 forbids redirects under /api/v1/*.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable               $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        $response = parent::render($request, $e);

        if ($this->isApiRequest($request) && $response->isRedirection()) {
            return JsonError::unauthenticated();
        }

        return $response;
    }

    /**
     * `/api/v1/*` paths and any request whose `Accept` header asks for JSON
     * receive the canonical envelope. Web routes (HTML) keep their default
     * Laravel error pages.
     */
    protected function isApiRequest(Request $request): bool
    {
        return $request->is('api/v1/*') || $request->expectsJson();
    }
}
