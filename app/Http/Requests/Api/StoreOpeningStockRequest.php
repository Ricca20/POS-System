<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `POST /api/v1/stock/opening` (task 4.4).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization mirrors the legacy `OpeningStockController` which gates
 * the action behind `product.opening_stock`. Opening stock is a one-time
 * initial-quantity setter for a (product, variation, location) tuple —
 * unlike adjustments and transfers it does not create a `transactions`
 * row, only the `variation_location_details` row that holds the qty.
 *
 * `qty` deliberately allows `0` (legitimate zero-on-hand initial state).
 */
class StoreOpeningStockRequest extends FormRequest
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

        return (bool) $user->can('product.opening_stock');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = pos_context('business.id');

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('business_id', $businessId),
            ],
            'variation_id' => ['required', 'integer'],
            'location_id' => [
                'required',
                'integer',
                Rule::exists('business_locations', 'id')->where('business_id', $businessId),
            ],
            'qty' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
