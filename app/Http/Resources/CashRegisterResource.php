<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterResource extends JsonResource
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
            'status' => $this->status,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location')->name ?? null,
            'user_id' => $this->user_id,
            'initial_amount' => $this->initial_amount,
            'closing_amount' => $this->closing_amount,
            'total_card_slips' => $this->total_card_slips,
            'total_cheques' => $this->total_cheques,
            'closing_note' => $this->closing_note,
            'closed_at' => $this->closed_at,
            'totals_by_payment_method' => $this->when(isset($this->totals_by_payment_method), $this->totals_by_payment_method),
            'created_at' => $this->created_at,
        ];
    }
}
