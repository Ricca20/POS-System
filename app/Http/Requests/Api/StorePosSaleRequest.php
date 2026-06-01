<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `POST /api/v1/pos/sales` (task 5.2).
 *
 * Validates: R13.1.
 *
 * Authorization mirrors the legacy `SellPosController::store` which gates
 * sale creation behind the `sell.create` Spatie permission. The auth
 * check additionally requires a resolved `pos.context` business id —
 * without it the controller has nowhere to scope foreign-key lookups
 * against, so we reject up-front with a 403 envelope.
 *
 * Foreign keys (`location_id`, `contact_id`) are constrained at the
 * validator stage via `Rule::exists` scoped to the authenticated user's
 * business so cross-business writes never reach the controller and
 * short-circuit with a 422 carrying the corresponding `errors.<field>`
 * key. This mirrors the convention established in
 * `StoreStockAdjustmentRequest` / `StoreStockTransferRequest`.
 *
 * Tax-rate id (`tax_rate_id`, both at the sale and per-line) is
 * scoped via `Rule::exists` against the auth business' `tax_rates`
 * rows so a SPA cannot reference another business' tax row.
 *
 * Scope deliberately deferred (per task 5.2 SCOPE NOTE):
 *   - Lots, modifiers, kots, weighing-scale, reward points, advance
 *     payment-account journaling.
 *   - Server-side enforcement that the payments sum equals the grand
 *     total. Payments shorter than the grand total are accepted and
 *     surface as `payment_status = 'partial' | 'due'` in the persisted
 *     row; the controller computes `payment_status` from the actual sum.
 */
class StorePosSaleRequest extends FormRequest
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

        return (bool) $user->can('sell.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = pos_context('business.id');

        return [
            'location_id' => [
                'required',
                'integer',
                Rule::exists('business_locations', 'id')->where('business_id', $businessId),
            ],
            'transaction_date' => ['required', 'date'],
            'contact_id' => [
                'required',
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

            // Line items — at least one is required. `gt:0` rejects zero
            // and negative quantities at the validator stage; `min:0`
            // on prices allows free items but rejects negative pricing.
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.variation_id' => ['required', 'integer'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.unit_price' => ['required', 'numeric', 'min:0'],
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

            // Payment lines — optional. Empty / missing means the sale
            // is recorded as `payment_status = 'due'`. Each entry, when
            // present, must specify a positive amount and one of the
            // canonical method keys defined in `App\Services\PaymentTypes`.
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
