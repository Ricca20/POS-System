<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
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
            'name' => $this->name,
            'account_number' => $this->account_number,
            'note' => $this->note,
            'account_type_id' => $this->account_type_id,
            'account_type_name' => $this->account_type_name,
            'parent_account_type_name' => $this->parent_account_type_name ?? $this->account_type_name,
            'account_details' => $this->account_details,
            'is_closed' => $this->is_closed,
            'balance' => $this->balance,
            'added_by' => $this->added_by,
            'created_at' => $this->created_at,
        ];
    }
}
