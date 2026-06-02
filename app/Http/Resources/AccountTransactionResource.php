<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account_name' => $this->account_name,
            'type' => $this->type,
            'sub_type' => $this->sub_type,
            'amount' => $this->amount,
            'operation_date' => $this->operation_date,
            'created_by' => $this->created_by,
            'transaction_id' => $this->transaction_id,
            'transaction_payment_id' => $this->transaction_payment_id,
            'transfer_transaction_id' => $this->transfer_transaction_id,
            'note' => $this->note,
            'balance' => $this->balance,
            'added_by' => $this->added_by,
            'created_at' => $this->created_at,
        ];
    }
}
