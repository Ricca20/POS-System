<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\ForceApiJsonResponse::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'language' => \App\Http\Middleware\Language::class,
        'timezone' => \App\Http\Middleware\Timezone::class,
        'SetSessionData' => \App\Http\Middleware\SetSessionData::class,
        // Sister middleware to `SetSessionData` for the JSON API. Loads the
        // same business/user/permission context but writes it into a
        // request-scoped `app('pos.context')` container instead of the PHP
        // session (R8.4, R8.5). Tasks 2.6+ apply this per-route.
        'SetSessionDataApi' => \App\Http\Middleware\SetSessionDataApi::class,
        // Server-side connectivity gate for online-only routes (SMS,
        // email, Pusher, license refresh, online payment gateways).
        // When `App\Services\ConnectivityProbe::isOnline()` returns
        // false, the middleware short-circuits with the canonical 503
        // `offline_required` envelope (R12.2, R12.3). Probe URL,
        // timeout, and cache TTL live in `config/desktop.php`.
        'online' => \App\Http\Middleware\OnlineGuard::class,
        'setData' => \App\Http\Middleware\IsInstalled::class,
        'authh' => \App\Http\Middleware\IsInstalled::class,
        'EcomApi' => \App\Http\Middleware\EcomApi::class,
        'AdminSidebarMenu' => \App\Http\Middleware\AdminSidebarMenu::class,
        'superadmin' => \App\Http\Middleware\Superadmin::class,
        'CheckUserLogin' => \App\Http\Middleware\CheckUserLogin::class,
    ];
}
