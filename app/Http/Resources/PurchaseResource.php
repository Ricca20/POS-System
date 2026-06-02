<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
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
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'contact' => new ContactResource($this->whenLoaded('contact')),
            'location' => [
                'id' => $this->whenLoaded('location')->id ?? null,
                'name' => $this->whenLoaded('location')->name ?? null,
                'location_id' => $this->whenLoaded('location')->location_id ?? null,
            ],
            'transaction_date' => $this->transaction_date,
            'total_before_tax' => $this->total_before_tax,
            'discount_type' => $this->discount_type,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'shipping_charges' => $this->shipping_charges,
            'final_total' => $this->final_total,
            'additional_notes' => $this->additional_notes,
            'purchase_lines' => $this->whenLoaded('purchase_lines'),
            'payment_lines' => PaymentResource::collection($this->whenLoaded('payment_lines')),
            'created_at' => $this->created_at,
        ];
    }
}
