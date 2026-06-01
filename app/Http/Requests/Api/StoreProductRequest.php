<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request for `POST /api/v1/products` (task 4.1).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization gate (mirrors legacy `ProductController::store` line 443):
 *   1. The Sanctum-authenticated user is present (R10.6).
 *   2. The `SetSessionDataApi` middleware has populated the request-scoped
 *      POS context with a business id (R8.5) — without it, scoping would
 *      silently fall through to the global table.
 *   3. The user has the legacy `product.create` Spatie permission.
 *
 * Returning `false` causes Laravel to throw `AuthorizationException`,
 * which the central `App\Exceptions\Handler` translates into the
 * canonical 403 envelope (`code = forbidden`).
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            return false;
        }

        if (pos_context('business.id') === null) {
            return false;
        }

        return (bool) $user->can('product.create');
    }

    /**
     * Validation rules for the basic-CRUD subset of product fields. The
     * full legacy field list (variations, image upload, kits, modifiers,
     * weighing-scale, opening stock, repair, essentials) is deliberately
     * out of scope for this leaf task; see `ProductResource` for the
     * companion field-selection rationale.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:single,variable'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:191'],
            'barcode_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'unit_id' => ['required', 'integer'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'sub_category_id' => ['sometimes', 'nullable', 'integer'],
            'brand_id' => ['sometimes', 'nullable', 'integer'],
            'tax' => ['sometimes', 'nullable', 'integer'],
            'tax_type' => ['sometimes', 'in:inclusive,exclusive'],
            'enable_stock' => ['sometimes', 'boolean'],
            'alert_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'string', 'max:32'],
            'product_description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'not_for_selling' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Override the default Laravel 422 envelope so it carries the
     * canonical `validation_failed` shape via `JsonError` (task 2.4).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
