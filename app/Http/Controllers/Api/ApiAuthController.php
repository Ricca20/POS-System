<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\JsonError;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sanctum stateful auth for the desktop SPA (R10.1–R10.6, P7).
 *
 * Mirrors the legacy `App\Http\Controllers\Auth\LoginController` post-auth
 * state checks (business active, user active, allow_login, CRM subscription
 * for `user_customer`) so JSON parity matches the web flow exactly.
 */
class ApiAuthController extends Controller
{
    /**
     * Path to `modules_statuses.json` relative to the project base path.
     */
    private const MODULES_STATUSES_FILE = 'modules_statuses.json';

    /**
     * Authenticate the user and return a `LoginResponse` envelope.
     *
     * Accepts a single `login` field that is matched first against
     * `username` (legacy default) and, if that fails and the value contains
     * an `@`, against `email` (per R10.2 wording).
     *
     * Validates: R10.1, R10.2, R10.3.
     */
    public function login(LoginRequest $request, BusinessUtil $businessUtil, ModuleUtil $moduleUtil): JsonResponse
    {
        $login = (string) $request->input('login');
        $password = (string) $request->input('password');

        $authenticated = Auth::attempt(['username' => $login, 'password' => $password]);

        if (! $authenticated && str_contains($login, '@')) {
            $authenticated = Auth::attempt(['email' => $login, 'password' => $password]);
        }

        if (! $authenticated) {
            // Centralized via JsonError (task 2.4) — every 422 envelope across the
            // API now flows through the same helper for shape consistency (P3).
            return JsonError::validationFailed([
                'login' => ['The provided credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var \App\User $user */
        $user = Auth::user();

        // Run state checks before logging activity; mirrors legacy order.
        if ($denied = $this->failPostAuthChecks($user, $moduleUtil)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $denied;
        }

        // Activity log AFTER state checks so we don't record "login" for a
        // user that ultimately gets rejected. Best-effort: a failure in the
        // audit pipeline must never lock a legitimate user out.
        try {
            $businessUtil->activityLog($user, 'login', null, [], false, $user->business_id);
        } catch (\Throwable $e) {
            \Log::warning('Failed to log login activity: '.$e->getMessage());
        }

        return response()->json([
            'data' => $this->buildUserEnvelope($user),
        ], 200);
    }

    /**
     * Return the currently authenticated user (Sanctum stateful).
     *
     * Validates: R10.3.
     */
    public function me(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            // Centralized via JsonError (task 2.4) — keeps the unauthenticated
            // envelope identical whether it is produced here or by the central
            // exception handler when `auth:sanctum` rejects the request.
            return JsonError::unauthenticated();
        }

        return response()->json([
            'data' => $this->buildUserEnvelope($user),
        ], 200);
    }

    /**
     * Invalidate the session and return 204.
     *
     * Validates: R10.4.
     */
    public function logout(Request $request, BusinessUtil $businessUtil): JsonResponse
    {
        /** @var \App\User $user */
        $user = auth('sanctum')->user();

        if ($user !== null) {
            try {
                $businessUtil->activityLog($user, 'logout', null, [], false, $user->business_id);
            } catch (\Throwable $e) {
                // Activity log is best-effort; never block the logout itself.
                \Log::warning('Failed to log logout activity: '.$e->getMessage());
            }
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Forget the cached user on every guard so the next call to
        // `auth('sanctum')->user()` cannot return the in-memory copy held
        // by the SessionGuard before invalidation.
        auth()->forgetGuards();

        return response()->json(null, 204);
    }

    /**
     * Apply the four post-authentication state checks from the legacy
     * `LoginController::authenticated()` and return the matching JSON 403
     * envelope, or `null` when the user is allowed through.
     *
     * Each branch funnels through `JsonError::forbidden()` (task 2.4) so
     * the canonical envelope is identical with framework-emitted 403s,
     * while preserving the domain-specific `code` per legacy parity.
     *
     * Validates: R10.2.
     */
    private function failPostAuthChecks($user, ModuleUtil $moduleUtil): ?JsonResponse
    {
        $business = $user->business;

        if ($business !== null && ! $business->is_active) {
            return JsonError::forbidden(
                'business_inactive',
                'This business is currently inactive. Contact your administrator.'
            );
        }

        if ($user->status !== 'active') {
            return JsonError::forbidden(
                'user_inactive',
                'This user account is not active.'
            );
        }

        if (! $user->allow_login) {
            return JsonError::forbidden(
                'login_not_allowed',
                'Login is not allowed for this user.'
            );
        }

        if ($user->user_type === 'user_customer'
            && ! $moduleUtil->hasThePermissionInSubscription($user->business_id, 'crm_module')) {
            return JsonError::forbidden(
                'crm_subscription_required',
                'This business does not have an active CRM subscription.'
            );
        }

        return null;
    }

    /**
     * Build a minimal LoginResponse envelope.
     *
     * The user shape is delegated to `UserResource` (task 3.3) so the
     * `data.user` contract is centralized and stays consistent between
     * `login` and `me`. The wrapping `data` envelope keeps the rest of
     * the SPA bootstrap payload (business, currency, modules) alongside
     * the user object as the SPA's `LoginResponse` model expects.
     */
    private function buildUserEnvelope($user): array
    {
        $user->loadMissing('business.currency');

        $business = $user->business;
        $currency = $business?->currency;

        return [
            'user' => (new UserResource($user))->toArray(request()),
            'business' => $business === null ? null : [
                'id' => $business->id,
                'name' => $business->name,
                'is_active' => (bool) $business->is_active,
                'currency_id' => $business->currency_id,
            ],
            'permissions' => [],
            'locations' => [],
            'currency' => $currency === null ? null : [
                'id' => $currency->id,
                'code' => $currency->code ?? null,
                'symbol' => $currency->symbol ?? null,
            ],
            'modules_enabled' => $this->enabledModuleKeys(),
        ];
    }

    /**
     * Read `modules_statuses.json` and return the keys whose flag is true.
     *
     * Returns an empty array if the file is missing or unreadable; the SPA
     * treats absence the same as "no module enabled".
     */
    private function enabledModuleKeys(): array
    {
        $path = base_path(self::MODULES_STATUSES_FILE);

        if (! is_readable($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $enabled = [];
        foreach ($decoded as $module => $flag) {
            if ($flag === true) {
                $enabled[] = (string) $module;
            }
        }

        return $enabled;
    }
}
