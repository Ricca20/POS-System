<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'business_id' => $this->business_id,
            'is_return' => $this->is_return,
            'amount' => $this->amount,
            'method' => $this->method,
            'payment_ref_no' => $this->payment_ref_no,
            'payment_for' => $this->payment_for,
            'paid_on' => $this->paid_on,
            'note' => $this->note,
            'card_transaction_number' => $this->card_transaction_number,
            'card_number' => $this->card_number,
            'card_type' => $this->card_type,
            'card_holder_name' => $this->card_holder_name,
            'card_month' => $this->card_month,
            'card_year' => $this->card_year,
            'card_security' => $this->card_security,
            'cheque_number' => $this->cheque_number,
            'bank_account_number' => $this->bank_account_number,
            'transaction_no' => $this->transaction_no,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
