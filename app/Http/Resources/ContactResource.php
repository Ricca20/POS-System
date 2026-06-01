<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
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
            'type' => $this->type,
            'name' => $this->name,
            'supplier_business_name' => $this->supplier_business_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'tax_number' => $this->tax_number,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'zip_code' => $this->zip_code,
            'customer_group_id' => $this->customer_group_id,
            'contact_id' => $this->contact_id,
            'credit_limit' => $this->credit_limit,
            'balance' => $this->balance,
            'pay_term_number' => $this->pay_term_number,
            'pay_term_type' => $this->pay_term_type,
            'contact_status' => $this->contact_status,
            'is_default' => $this->is_default,
            
            // Computed fields from the aggregated query (if loaded)
            'total_purchase' => $this->when(isset($this->total_purchase), $this->total_purchase),
            'total_invoice' => $this->when(isset($this->total_invoice), $this->total_invoice),
            
            'created_at' => $this->created_at,
        ];
    }
}
