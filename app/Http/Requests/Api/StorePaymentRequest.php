<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePaymentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'transaction_id' => 'required|integer|exists:transactions,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:cash,card,cheque,bank_transfer,advance,custom_pay_1,custom_pay_2,custom_pay_3,custom_pay_4,custom_pay_5,custom_pay_6,custom_pay_7',
            'paid_on' => 'nullable|date',
            'note' => 'nullable|string',
            'account_id' => 'nullable|integer',
            'card_number' => 'nullable|string',
            'card_holder_name' => 'nullable|string',
            'card_transaction_number' => 'nullable|string',
            'card_type' => 'nullable|string',
            'card_month' => 'nullable|string',
            'card_year' => 'nullable|string',
            'card_security' => 'nullable|string',
            'cheque_number' => 'nullable|string',
            'bank_account_number' => 'nullable|string',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
