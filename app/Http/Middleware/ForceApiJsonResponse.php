<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth for R8.3: paths under `/api/v1/*` SHALL NOT issue HTTP
 * redirects.
 *
 * `App\Exceptions\Handler::render()` already coerces redirects produced by
 * the exception pipeline (e.g. `Authenticate::redirectTo()`), but a
 * controller or route closure can still return a `RedirectResponse`
 * directly without raising an exception — that response never reaches
 * `Handler::render()`. This middleware closes that gap by intercepting any
 * 3xx response on an API request and replacing it with the canonical
 * `unauthenticated` envelope so the SPA never has to follow `Location`.
 *
 * Validates: R8.3.
 * Property: P3 (API JSON purity).
 */
class ForceApiJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->isApiRequest($request)) {
            return $response;
        }

        if ($response->isRedirection()) {
            return JsonError::unauthenticated();
        }

        return $response;
    }

    /**
     * Same predicate as `App\Exceptions\Handler::isApiRequest()`. Kept in
     * sync with that method's logic — any future broadening (e.g. to
     * `/api/v2/*`) must be applied in both places.
     */
    private function isApiRequest(Request $request): bool
    {
        return $request->is('api/v1/*') || $request->expectsJson();
    }
}
