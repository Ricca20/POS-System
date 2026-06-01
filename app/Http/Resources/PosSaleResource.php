<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a POS sale (`App\Transaction` with `type='sell'`,
 * `sub_type='pos'`) returned by `POST /api/v1/pos/sales` (task 5.2).
 *
 * Validates: R13.1, R8.1, R8.2.
 *
 * Field selection rationale
 * -------------------------
 * Mirrors the columns the SPA needs to render a freshly-created sale
 * (totals, payment-status badge, audit metadata) and to enable the
 * "show recent sales" / "open receipt" follow-on flows in tasks 5.3 /
 * 5.4. Internal scratch columns (rp_redeemed, types_of_service_id,
 * commission agents, etc.) are intentionally excluded — they belong
 * with their dedicated endpoints when those features are wired in.
 *
 * Decimal columns (`total_before_tax`, `tax_amount`, `discount_amount`,
 * `shipping_charges`, `final_total`) are force-cast to `float` so the
 * SPA can use strict numeric comparisons without coercion guards.
 *
 * `sell_lines` and `payments` are exposed via `whenLoaded()` so a
 * future caller that does not eager-load them gets keys absent rather
 * than misleading `null`s. The controller's create flow eager-loads
 * both, so they are always present in the 201 response payload.
 */
class PosSaleResource extends JsonResource
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
            // Identity / scoping
            'id' => $this->id,
            'business_id' => $this->business_id,
            'location_id' => $this->location_id,
            'contact_id' => $this->contact_id,

            // Invoice / dates
            'invoice_no' => $this->invoice_no,
            'transaction_date' => optional($this->transaction_date instanceof \DateTimeInterface
                ? $this->transaction_date
                : ($this->transaction_date ? \Carbon\Carbon::parse($this->transaction_date) : null)
            )?->toIso8601String(),

            // Totals — all decimals come back as floats so the SPA can
            // rely on strict numeric comparisons.
            'total_before_tax' => (float) $this->total_before_tax,
            'tax_amount' => (float) $this->tax_amount,
            'discount_type' => $this->discount_type,
            'discount_amount' => (float) $this->discount_amount,
            'shipping_charges' => (float) $this->shipping_charges,
            'final_total' => (float) $this->final_total,

            // Status flags
            'payment_status' => $this->payment_status,

            // Misc
            'additional_notes' => $this->additional_notes,

            // Audit
            'created_by' => $this->created_by,
            'created_at' => optional($this->created_at)->toIso8601String(),

            // Embedded relations — controller eager-loads both, but a
            // future caller that forgoes them degrades cleanly to
            // absent keys via whenLoaded().
            'sell_lines' => $this->whenLoaded('sell_lines', fn () => $this->sell_lines->map(fn ($line) => [
                'id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'variation_id' => (int) $line->variation_id,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'unit_price_inc_tax' => (float) $line->unit_price_inc_tax,
                'tax_id' => $line->tax_id !== null ? (int) $line->tax_id : null,
                'item_tax' => (float) $line->item_tax,
                'line_discount_type' => $line->line_discount_type,
                'line_discount_amount' => (float) $line->line_discount_amount,
            ])->all()),

            'payments' => $this->whenLoaded('payment_lines', fn () => $this->payment_lines->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'paid_on' => optional($payment->paid_on instanceof \DateTimeInterface
                    ? $payment->paid_on
                    : ($payment->paid_on ? \Carbon\Carbon::parse($payment->paid_on) : null)
                )?->toIso8601String(),
                'payment_ref_no' => $payment->payment_ref_no,
            ])->all()),
        ];
    }
}
