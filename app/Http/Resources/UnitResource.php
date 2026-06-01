<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\Unit` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Units form a two-level hierarchy: base units (`base_unit_id = null`)
 * and sub-units (`base_unit_id = <parent_id>`, `base_unit_multiplier`
 * giving the conversion factor). The SPA needs both columns to render
 * conversions in the POS quantity widget, so the resource exposes them
 * verbatim.
 *
 * `allow_decimal` is a tinyint flag on the legacy schema; force-casting
 * to `bool` keeps the JSON contract clean.
 */
class UnitResource extends JsonResource
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
            'business_id' => $this->business_id,
            'actual_name' => $this->actual_name,
            'short_name' => $this->short_name,
            'allow_decimal' => $this->allow_decimal === null
                ? null
                : (bool) $this->allow_decimal,

            // Base / sub-unit linkage. `base_unit_multiplier` is decimal
            // in the legacy schema and arrives as a string from PDO; the
            // SPA tolerates either, but exposing it raw avoids a hidden
            // numeric coercion.
            'base_unit_id' => $this->base_unit_id,
            'base_unit_multiplier' => $this->base_unit_multiplier,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
