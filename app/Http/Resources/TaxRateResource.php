<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\TaxRate` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Tax rates carry two flags that drive POS behavior:
 *
 *   - `is_tax_group`: when true, the row aggregates several other tax
 *     rates via `group_sub_taxes`. The aggregated child rates are NOT
 *     surfaced here; the SPA can fetch them by id when it needs the
 *     breakdown. Keeping the resource flat avoids a heavy default
 *     payload on the dropdown endpoint.
 *
 *   - `for_tax_group`: when true, the row is meant to participate in
 *     groups but not appear standalone in the POS dropdown (the legacy
 *     `ExcludeForTaxGroup` scope filters these out). The resource
 *     exposes the flag so the SPA can apply the same filter in client
 *     code if it wants to mirror the legacy default UX.
 *
 * `amount` is force-cast to `float` (decimal column in the legacy schema
 * arrives as a string from PDO).
 */
class TaxRateResource extends JsonResource
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
            'name' => $this->name,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'is_tax_group' => (bool) $this->is_tax_group,
            'for_tax_group' => (bool) $this->for_tax_group,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
