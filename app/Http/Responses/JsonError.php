<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Single source of truth for the canonical error envelope returned by
 * every /api/v1/* endpoint.
 *
 *   { "code": String, "message": String, "errors": Map|null }
 *
 * Validates: R8.2, R8.3, R10.4, R10.5.
 * Property: P3 (API JSON purity). The dedicated property fuzz test for
 * P3 lives in task 2.11
 * (`tests/Feature/Property/ApiJsonPurityPropertyTest.php`); this helper
 * is the production code that test exercises.
 *
 * Convenience static methods exist for every code emitted by the central
 * `App\Exceptions\Handler::register()` so controllers, form requests, and
 * middleware never compose error JSON inline.
 */
final class JsonError
{
    /**
     * Build a JSON envelope with an explicit status, code, message, and
     * optional `errors` map (used by 422 validation responses only).
     */
    public static function make(int $status, string $code, string $message, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): JsonResponse
    {
        return self::make(404, 'not_found', $message);
    }

    public static function unauthenticated(string $message = 'Authentication is required to access this resource.'): JsonResponse
    {
        return self::make(401, 'unauthenticated', $message);
    }

    /**
     * 403 envelope with a caller-supplied `code` so domain-specific
     * forbiddens (`business_inactive`, `user_inactive`, etc.) keep their
     * granularity while sharing the same shape.
     */
    public static function forbidden(string $code = 'forbidden', string $message = 'You do not have permission to perform this action.'): JsonResponse
    {
        return self::make(403, $code, $message);
    }

    public static function validationFailed(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::make(422, 'validation_failed', $message, $errors);
    }

    public static function csrfMismatch(string $message = 'CSRF token mismatch.'): JsonResponse
    {
        return self::make(419, 'csrf_mismatch', $message);
    }

    /**
     * Used by the OnlineGuard middleware (task 2.8) — a 503 response when
     * connectivity to an upstream service is required but unavailable.
     */
    public static function offlineRequired(string $message = 'This feature requires an internet connection.'): JsonResponse
    {
        return self::make(503, 'offline_required', $message);
    }

    public static function serverError(string $message = 'An unexpected error occurred.'): JsonResponse
    {
        return self::make(500, 'server_error', $message);
    }
}
