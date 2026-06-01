<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `POST /api/v1/sell-returns` (task 5.5).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization mirrors the legacy permission key `access_sell_return`,
 * which the original `SellReturnController` uses to gate the entire
 * sell-return module. We additionally require:
 *   - a Sanctum-authenticated user, and
 *   - a resolved `pos.context` business id (so foreign-key scoping has a
 *     business to bind to).
 *
 * Both conditions match the convention in `StorePosSaleRequest`.
 *
 * `parent_sale_id` is constrained at the validator stage via
 * `Rule::exists` scoped to the auth business AND `type='sell'` so a SPA
 * cannot reference another business' sale (or a non-sale row) and have
 * the controller silently process it.
 */
class StoreSellReturnRequest extends FormRequest
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

        return (bool) $user->can('access_sell_return');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = pos_context('business.id');

        return [
            'parent_sale_id' => [
                'required',
                'integer',
                Rule::exists('transactions', 'id')
                    ->where('business_id', $businessId)
                    ->where('type', 'sell'),
            ],
            'transaction_date' => ['required', 'date'],
            'additional_notes' => ['sometimes', 'nullable', 'string'],

            // Line items — at least one. `gt:0` rejects zero/negative
            // quantities; `min:0` lets line items be free but rejects
            // negative pricing.
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.variation_id' => ['required', 'integer'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.unit_price' => ['required', 'numeric', 'min:0'],
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
