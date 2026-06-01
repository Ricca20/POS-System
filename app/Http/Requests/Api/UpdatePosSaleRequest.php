<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `PUT /api/v1/pos/sales/{id}` (task 5.3).
 *
 * Validates: R8.1, R8.2.
 *
 * Mirrors {@see StorePosSaleRequest} in shape but every field downgrades
 * to `sometimes` because edits are partial — the SPA only sends the
 * fields the user actually changed. The controller treats any field
 * absent from the request as "leave as-is" against the stored
 * transaction. Foreign-key scoping (location, contact, tax) is still
 * enforced at the validator stage to keep cross-business writes from
 * reaching the controller.
 *
 * Authorization gate (mirrors legacy `SellPosController::update` line 1112):
 *   1. The Sanctum-authenticated user is present (R10.6).
 *   2. The POS context has a business id (R8.5).
 *   3. The user has the legacy `sell.update` Spatie permission.
 *
 * The legacy controller also accepts `direct_sell.access`, `so.update`
 * and `edit_pos_payment` as alternate gates, but those flows are
 * deferred — the API exposes only the canonical "edit sale" path which
 * requires `sell.update`.
 *
 * If `products` is supplied, it MUST be a non-empty array; you cannot
 * use the edit endpoint to clear a sale's lines. To void a sale, use
 * the dedicated destroy endpoint (task 5.5).
 */
class UpdatePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            return false;
        }

        if (pos_context('business.id') === null) {
            return false;
        }

        return (bool) $user->can('sell.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = pos_context('business.id');

        return [
            'location_id' => [
                'sometimes',
                'integer',
                Rule::exists('business_locations', 'id')->where('business_id', $businessId),
            ],
            'transaction_date' => ['sometimes', 'date'],
            'contact_id' => [
                'sometimes',
                'integer',
                Rule::exists('contacts', 'id')->where('business_id', $businessId),
            ],
            'invoice_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'discount_type' => ['sometimes', 'in:percentage,fixed'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('tax_rates', 'id')->where('business_id', $businessId),
            ],
            'tax_amount' => ['sometimes', 'numeric', 'min:0'],
            'shipping_charges' => ['sometimes', 'numeric', 'min:0'],
            'additional_notes' => ['sometimes', 'nullable', 'string'],

            // Line items — `sometimes` here means "if the SPA supplies a
            // products key at all, it must be a non-empty array of valid
            // line shapes." Omitting `products` leaves the existing
            // lines untouched.
            'products' => ['sometimes', 'array', 'min:1'],
            'products.*.product_id' => ['required_with:products', 'integer'],
            'products.*.variation_id' => ['required_with:products', 'integer'],
            'products.*.quantity' => ['required_with:products', 'numeric', 'gt:0'],
            'products.*.unit_price' => ['required_with:products', 'numeric', 'min:0'],
            'products.*.unit_price_inc_tax' => ['sometimes', 'numeric', 'min:0'],
            'products.*.tax_rate_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('tax_rates', 'id')->where('business_id', $businessId),
            ],
            'products.*.item_tax' => ['sometimes', 'numeric', 'min:0'],
            'products.*.line_discount_type' => ['sometimes', 'in:percentage,fixed'],
            'products.*.line_discount_amount' => ['sometimes', 'numeric', 'min:0'],

            // Payment lines — when supplied, the controller replaces all
            // existing payments. Omitting `payments` leaves the existing
            // payments untouched.
            'payments' => ['sometimes', 'array'],
            'payments.*.amount' => ['required_with:payments.*', 'numeric', 'gt:0'],
            'payments.*.method' => [
                'required_with:payments.*',
                'in:cash,card,cheque,bank_transfer,other,advance,custom_pay_1,custom_pay_2,custom_pay_3',
            ],
            'payments.*.paid_on' => ['sometimes', 'date'],
            'payments.*.payment_ref_no' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Override the default Laravel 422 envelope so it carries the
     * canonical `validation_failed` shape via `JsonError` (task 2.4).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
