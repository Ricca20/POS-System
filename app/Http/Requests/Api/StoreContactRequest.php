<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        // Handled in the controller via policies.
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
            'type' => 'required|in:customer,supplier,both',
            'first_name' => 'required|string|max:255',
            'mobile' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_id' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric',
            'opening_balance' => 'nullable|numeric',
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
