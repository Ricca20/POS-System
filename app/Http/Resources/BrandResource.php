<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\Brands` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * The legacy model class is `App\Brands` (plural) backed by the singular
 * `brands` table. The resource keeps the column-level naming
 * (`use_for_repair`) so the SPA can map directly without a translation
 * layer. `use_for_repair` is added by the Repair module's migration; it
 * is force-cast to `bool` so the SPA receives a JSON boolean rather than
 * MySQL's `tinyint(1)` integer.
 */
class BrandResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_id' => $this->business_id,
            'description' => $this->description,

            // The Repair module flag — null on installations without
            // the column, otherwise force-cast to a JSON boolean.
            'use_for_repair' => $this->use_for_repair === null
                ? null
                : (bool) $this->use_for_repair,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
