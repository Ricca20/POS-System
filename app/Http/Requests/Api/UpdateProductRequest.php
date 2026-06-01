<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request for `PUT /api/v1/products/{id}` (task 4.1).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization gate (mirrors legacy `ProductController::update` line 674):
 *   1. The Sanctum-authenticated user is present (R10.6).
 *   2. The POS context has a business id (R8.5).
 *   3. The user has the legacy `product.update` Spatie permission.
 *
 * Every rule uses `sometimes` because this is a partial update — the SPA
 * only sends the fields the user actually changed. Any field outside
 * the rule set is silently dropped (`validated()` only returns
 * rule-named keys), preventing drive-by mass assignment of legacy
 * columns the Product model exposes via `$guarded = ['id']`.
 */
class UpdateProductRequest extends FormRequest
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

        return (bool) $user->can('product.update');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'type' => ['sometimes', 'in:single,variable'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:191'],
            'barcode_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'unit_id' => ['sometimes', 'integer'],
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
