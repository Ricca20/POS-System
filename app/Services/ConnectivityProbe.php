<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side connectivity probe used by the `online` middleware
 * (`App\Http\Middleware\OnlineGuard`) to decide whether to allow a
 * request to a route that performs outbound work (SMS send, online
 * payment capture, license refresh, etc.).
 *
 * Implementation notes:
 *
 *   - The probe issues a HEAD request to `config('desktop.connectivity_probe_url')`
 *     with a short timeout. The default target is `gstatic.com/generate_204`
 *     because it returns 204 with no body and is reachable from any edge
 *     that has working internet, even when content filters block other
 *     hostnames.
 *   - The result is memoised in the cache for `connectivity_probe_cache_ttl_seconds`
 *     (default 30 s) so a burst of requests guarded by `OnlineGuard` does
 *     not each issue its own outbound HEAD. The TTL deliberately matches
 *     the Vue SPA's heartbeat cadence (R11.1) so the server- and client-side
 *     views of "online" stay roughly in sync.
 *   - Every failure mode (connection refused, DNS failure, TLS error,
 *     timeout, 5xx response) yields `false` rather than bubbling. The
 *     middleware should never crash the request because the upstream
 *     reachability check itself errored.
 *
 * Validates: R12.2, R12.3.
 */
class ConnectivityProbe
{
    /**
     * Cache key for the memoised probe result. Namespaced under `pos:` so
     * a future site-wide cache flush from this app's code can target it.
     */
    private const CACHE_KEY = 'pos:connectivity';

    /**
     * Return whether the host is currently reachable.
     *
     * The result is cached for `desktop.connectivity_probe_cache_ttl_seconds`
     * seconds; subsequent calls within that window do not perform any I/O.
     */
    public function isOnline(): bool
    {
        $ttl = (int) config('desktop.connectivity_probe_cache_ttl_seconds', 30);

        // `Cache::remember` returns whatever the closure produced on the
        // first call within the TTL, so the boolean is preserved across
        // reads even when the cache store stringifies values.
        return (bool) Cache::remember(
            self::CACHE_KEY,
            $ttl,
            fn (): bool => $this->probe(),
        );
    }

    /**
     * Issue the actual HEAD request. Wrapped so callers (notably tests)
     * can also exercise the uncached path.
     *
     * Returns `false` for any failure mode, including non-2xx responses,
     * connection failures, DNS errors, and timeouts. `\Throwable` is
     * caught (rather than just `Exception`) so PHP-level errors raised
     * inside Guzzle do not leak into request handling either.
     */
    public function probe(): bool
    {
        $url = (string) config('desktop.connectivity_probe_url', 'https://www.gstatic.com/generate_204');
        $timeout = (int) config('desktop.connectivity_probe_timeout_seconds', 3);

        try {
            $response = Http::timeout($timeout)->head($url);

            // `successful()` returns true for any 2xx status, which
            // covers `generate_204`'s 204 as well as alternative probe
            // targets that return 200.
            return $response->successful();
        } catch (Throwable $e) {
            return false;
        }
    }
}
