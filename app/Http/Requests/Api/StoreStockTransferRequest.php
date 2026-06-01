<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `POST /api/v1/stock/transfer` (task 4.4).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization gates the request behind the legacy `purchase.create`
 * permission, the same convention applied to stock adjustments.
 *
 * Both location ids are constrained to belong to the authenticated
 * business, and a `different:from_location_id` rule on `to_location_id`
 * rejects same-source-and-destination transfers at the validator stage.
 */
class StoreStockTransferRequest extends FormRequest
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

        return (bool) $user->can('purchase.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = pos_context('business.id');

        return [
            'from_location_id' => [
                'required',
                'integer',
                Rule::exists('business_locations', 'id')->where('business_id', $businessId),
            ],
            'to_location_id' => [
                'required',
                'integer',
                'different:from_location_id',
                Rule::exists('business_locations', 'id')->where('business_id', $businessId),
            ],
            'ref_no' => ['sometimes', 'nullable', 'string', 'max:191'],
            'transaction_date' => ['required', 'date'],
            'additional_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'shipping_charges' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.variation_id' => ['required', 'integer'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
