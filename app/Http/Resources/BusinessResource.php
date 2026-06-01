<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for `App\Business` exposed to the desktop SPA.
 *
 * Validates: R8.1, R8.2 (JSON-only).
 *
 * Field selection rationale
 * -------------------------
 * The legacy `business` table has roughly 100 columns. The desktop SPA
 * does not render every legacy screen field on day one, so this resource
 * intentionally exposes a *core subset* sufficient for the settings
 * screen + bootstrap rendering. Tasks that need additional fields can
 * extend this resource.
 *
 * Deliberately NOT exposed
 * ------------------------
 *   - `keyboard_shortcuts`     large opaque JSON blob; SPA owns shortcuts
 *   - `pos_settings`           large opaque JSON blob with POS UX flags
 *   - `email_settings`         contains SMTP credentials (host, port,
 *                              username, password)
 *   - `sms_settings`           contains SMS-gateway API keys / tokens
 *   - `woocommerce_api_settings` already hidden on the model itself
 *
 * Exposing those would either bloat the bootstrap response or leak
 * credentials. They are managed through dedicated future endpoints
 * (e.g. `/api/v1/business/email-settings`) where access can be gated
 * with finer-grained permissions.
 */
class BusinessResource extends JsonResource
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
            // Identity
            'id' => $this->id,
            'name' => $this->name,
            'currency_id' => $this->currency_id,
            'start_date' => $this->start_date,
            'time_zone' => $this->time_zone,
            'fy_start_month' => $this->fy_start_month,
            'accounting_method' => $this->accounting_method,

            // Tax labels / numbers
            'tax_label_1' => $this->tax_label_1,
            'tax_number_1' => $this->tax_number_1,
            'tax_label_2' => $this->tax_label_2,
            'tax_number_2' => $this->tax_number_2,

            // Pricing defaults
            'default_profit_percent' => $this->default_profit_percent,
            'default_sales_tax' => $this->default_sales_tax,
            'default_sales_discount' => $this->default_sales_discount,
            'sell_price_tax' => $this->sell_price_tax,
            'sku_prefix' => $this->sku_prefix,
            'transaction_edit_days' => $this->transaction_edit_days,

            // Display / locale
            'currency_symbol_placement' => $this->currency_symbol_placement,
            'currency_precision' => $this->currency_precision,
            'quantity_precision' => $this->quantity_precision,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'theme_color' => $this->theme_color,

            // Reward points
            'enable_rp' => (bool) $this->enable_rp,
            'rp_name' => $this->rp_name,
            'amount_for_unit_rp' => $this->amount_for_unit_rp,

            // Inventory toggles
            'enable_brand' => (bool) $this->enable_brand,
            'enable_category' => (bool) $this->enable_category,
            'enable_sub_category' => (bool) $this->enable_sub_category,
            'enable_price_tax' => (bool) $this->enable_price_tax,

            // Status / metadata
            'is_active' => (bool) $this->is_active,
            'logo' => $this->logo,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),

            // Embedded currency: only included when the relation is
            // already loaded (controller calls `with('currency')`). This
            // avoids an N+1 surprise if a future caller forgets to load.
            'currency' => $this->whenLoaded('currency', function () {
                $c = $this->currency;

                return [
                    'id' => $c->id,
                    'code' => $c->code ?? null,
                    'symbol' => $c->symbol ?? null,
                    'country' => $c->country ?? null,
                    'currency' => $c->currency ?? null,
                ];
            }),
        ];
    }
}
