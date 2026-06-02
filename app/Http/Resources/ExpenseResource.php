<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
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
            'ref_no' => $this->ref_no,
            'transaction_date' => $this->transaction_date,
            'category' => $this->whenLoaded('expenseCategory')->name ?? null,
            'sub_category' => $this->whenLoaded('expenseSubCategory')->name ?? null,
            'location' => $this->whenLoaded('location')->name ?? null,
            'payment_status' => $this->payment_status,
            'final_total' => $this->final_total,
            'amount_paid' => $this->amount_paid ?? 0,
            'additional_notes' => $this->additional_notes,
            'expense_for' => $this->whenLoaded('expenseFor')->user_full_name ?? null,
            'contact_name' => $this->whenLoaded('contact')->name ?? null,
            'type' => $this->type,
            'is_recurring' => $this->is_recurring,
            'created_at' => $this->created_at,
        ];
    }
}
