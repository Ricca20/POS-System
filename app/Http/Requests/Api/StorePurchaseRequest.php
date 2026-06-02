<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePurchaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'status' => 'required|in:received,pending,ordered',
            'contact_id' => 'required|integer|exists:contacts,id',
            'transaction_date' => 'required|date',
            'location_id' => 'required|integer|exists:business_locations,id',
            'purchases' => 'required|array',
            'purchases.*.product_id' => 'required|integer',
            'purchases.*.variation_id' => 'required|integer',
            'purchases.*.purchase_quantity' => 'required|numeric',
            'purchases.*.purchase_price' => 'required|numeric',
            'final_total' => 'required|numeric',
        ];
    }

    /**
     * Use standard JSON error response for validation failures.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
