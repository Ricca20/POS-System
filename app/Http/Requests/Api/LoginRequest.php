<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Login form request for `POST /api/v1/auth/login`.
 *
 * Validates: R10.2.
 *
 * Note on the `login` field (legacy parity): the existing web
 * `LoginController::username()` returns `'username'`, and the `users` table
 * has both a `username` and an `email` column where `username` is the
 * canonical credential. The desktop SPA must accept either, so we expose a
 * single `login` field and let the controller try `username` first, then
 * `email` as a fallback. This keeps R10.2 ("valid email, password,
 * device_name") satisfied while preserving the legacy username-based flow.
 */
class LoginRequest extends FormRequest
{
    /**
     * Authorization is performed after validation by `Auth::attempt`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'min:1', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'device_name' => ['required', 'string', 'min:1', 'max:100'],
        ];
    }

    /**
     * Override the default Laravel 422 envelope so it carries our canonical
     * `code` field (`validation_failed`) alongside `message` and `errors`.
     *
     * Routed through `JsonError::validationFailed()` (task 2.4) so this
     * envelope is shaped identically with the central exception handler's
     * 422 path. The `Handler::render()` ValidationException renderable
     * returns null when `$e->response !== null`, so the response built
     * here passes through untouched.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
