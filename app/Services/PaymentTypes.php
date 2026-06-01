<?php

namespace App\Services;

use App\Business;

/**
 * Canonical payment type keys + labels for the POS SPA.
 *
 * Hard-coded for now; matches the keys returned by the legacy
 * `App\Utils\Util::payment_types()` helper. Custom payment labels
 * (`custom_pay_1..3`) come from the business `custom_labels` JSON column
 * when the business has overridden them — pass `$business` to merge.
 *
 * This service exists so `ApiBootstrapController::paymentTypes()` and
 * `PosApiController::config()` share one source of truth for the canonical
 * key set; whenever the SPA-side enum needs to grow, it grows here and
 * both endpoints pick up the change.
 *
 * Validates: R8.1, R8.2 (JSON-only payload returned by both endpoints).
 */
final class PaymentTypes
{
    /**
     * Return the default payment-type set, optionally with custom labels
     * applied from the supplied business.
     *
     * The shape is deliberately a flat list of `{key, label}` pairs rather
     * than a key→label map so the SPA can render it directly with `v-for`
     * and so the ordering is stable across responses.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function defaults(?Business $business = null): array
    {
        $types = [
            ['key' => 'cash', 'label' => 'Cash'],
            ['key' => 'card', 'label' => 'Card/Debit Card'],
            ['key' => 'cheque', 'label' => 'Cheque'],
            ['key' => 'bank_transfer', 'label' => 'Bank Transfer'],
            ['key' => 'other', 'label' => 'Other'],
            ['key' => 'advance', 'label' => 'Advance'],
            ['key' => 'custom_pay_1', 'label' => 'Custom Payment 1'],
            ['key' => 'custom_pay_2', 'label' => 'Custom Payment 2'],
            ['key' => 'custom_pay_3', 'label' => 'Custom Payment 3'],
        ];

        if ($business === null || empty($business->custom_labels)) {
            return $types;
        }

        // `custom_labels` is a JSON column in production but is not cast on
        // the Business model, so it may arrive as either a JSON string or
        // an already-decoded array. Normalise to an array; on parse error,
        // treat the column as absent rather than crashing the request.
        $custom = is_string($business->custom_labels)
            ? (json_decode($business->custom_labels, true) ?? [])
            : (array) $business->custom_labels;

        foreach (['custom_pay_1', 'custom_pay_2', 'custom_pay_3'] as $key) {
            if (! empty($custom[$key])) {
                foreach ($types as &$type) {
                    if ($type['key'] === $key) {
                        $type['label'] = (string) $custom[$key];
                    }
                }
                unset($type);
            }
        }

        return $types;
    }
}
