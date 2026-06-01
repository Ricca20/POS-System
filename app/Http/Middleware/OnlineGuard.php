<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonError;
use App\Services\ConnectivityProbe;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate online-only routes behind a server-side connectivity probe.
 *
 * Applied as the `online` middleware alias (see `App\Http\Kernel`) on any
 * Laravel route that performs outbound calls — SMS send, email send,
 * Pusher publish, license refresh, online payment gateway capture, and
 * similar. When `ConnectivityProbe::isOnline()` returns `false` the
 * request is short-circuited with the canonical 503 `offline_required`
 * envelope produced by `JsonError::offlineRequired()`. The probe URL is
 * configurable via `config('desktop.connectivity_probe_url')` so a
 * deployment can point at a regional fallback without code changes.
 *
 * Defence-in-depth note: `App\Exceptions\Handler::register()` already
 * maps `HttpException(503)` to the same envelope, so `abort(503)` would
 * also work. We return the JSON response directly to keep the happy path
 * out of the exception pipeline (cheaper, easier to reason about).
 *
 * Validates: R12.2, R12.3.
 */
class OnlineGuard
{
    public function __construct(private readonly ConnectivityProbe $probe)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->probe->isOnline()) {
            return $next($request);
        }

        return JsonError::offlineRequired();
    }
}
