<?php

/*
|--------------------------------------------------------------------------
| API request-scoped context helpers
|--------------------------------------------------------------------------
|
| Helpers exposed to controllers, form requests, and policies that run on
| the `/api/v1/*` pipeline. They read from the request-scoped container
| binding `pos.context` populated by `App\Http\Middleware\SetSessionDataApi`
| (task 2.5) instead of the PHP session, so API code is independent of
| `session()` (R8.4, R8.5).
|
| Web helpers in `app/Http/helpers.php` are unaffected; this file is loaded
| alongside it via `composer.json` `autoload.files`.
|
*/

if (! function_exists('pos_context')) {
    /**
     * Read the request-scoped POS context populated by the
     * `SetSessionDataApi` middleware (task 2.5).
     *
     * Returns the full context array, the value at a single dot-path, or
     * the supplied default when the key is missing or the middleware has
     * not run yet.
     *
     * Validates: R8.4, R8.5.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function pos_context(?string $key = null, $default = null)
    {
        if (! app()->bound('pos.context')) {
            return $default;
        }

        $ctx = app('pos.context');

        if ($key === null) {
            return $ctx;
        }

        return data_get($ctx, $key, $default);
    }
}
