<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\BusinessLocation` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Field selection rationale
 * -------------------------
 * Mirrors the columns the SPA needs to render the location list,
 * receipt-printer settings, and POS bootstrap data. Internal scratch
 * columns (`default_payment_accounts` JSON blob, custom field 1..4,
 * `sale_invoice_scheme_id`) are intentionally excluded — they belong
 * to dedicated endpoints when the SPA needs them.
 *
 * `featured_products` is already cast to `array` on the model, so we
 * simply forward the typed value rather than re-decoding here.
 *
 * `is_active` is force-cast to `bool` because the underlying column is
 * `tinyint(1)`; without the cast Eloquent would surface the integer
 * `0` / `1` and the SPA would have to do its own coercion.
 */
class BusinessLocationResource extends JsonResource
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
            // Identity / address
            'id' => $this->id,
            'name' => $this->name,
            'business_id' => $this->business_id,
            'landmark' => $this->landmark,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'zip_code' => $this->zip_code,

            // Contact
            'mobile' => $this->mobile,
            'alternate_number' => $this->alternate_number,
            'email' => $this->email,
            'website' => $this->website,

            // Operational
            'location_id' => $this->location_id,
            'is_active' => (bool) $this->is_active,
            'receipt_printer_type' => $this->receipt_printer_type,

            // Pricing / invoicing defaults
            'selling_price_group_id' => $this->selling_price_group_id,
            'invoice_scheme_id' => $this->invoice_scheme_id,
            'invoice_layout_id' => $this->invoice_layout_id,

            // Featured products grid (model casts to array).
            'featured_products' => $this->featured_products,

            // Metadata
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
