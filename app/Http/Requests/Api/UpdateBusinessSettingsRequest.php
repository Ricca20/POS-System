<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form request for `PUT /api/v1/business/settings`.
 *
 * Validates: R8.1, R8.2, R8.4.
 *
 * All rules use `sometimes` so the endpoint is a *partial* update — the
 * SPA only sends fields the user actually changed. Any field outside
 * this rule set is silently dropped (Laravel's `validated()` only
 * returns keys named in the rules), which protects the underlying
 * `business` row from accidental mass assignment of legacy columns.
 */
class UpdateBusinessSettingsRequest extends FormRequest
{
    /**
     * Authorization gate.
     *
     * Two checks must pass:
     *   1. The Sanctum-authenticated user is present (R10.6).
     *   2. The `SetSessionDataApi` middleware has populated the
     *      request-scoped POS context with a business id, i.e. the
     *      caller belongs to a business (R8.5).
     *   3. The user has the legacy `business_settings.access` Spatie
     *      permission, mirroring `BusinessController::postBusinessSettings`.
     *
     * Returning `false` causes Laravel to throw `AuthorizationException`,
     * which the central `App\Exceptions\Handler` translates into the
     * canonical 403 envelope (`code = forbidden`).
     */
    public function authorize(): bool
    {
        $user = auth('sanctum')->user();

        if ($user === null) {
            return false;
        }

        if (pos_context('business.id') === null) {
            return false;
        }

        return (bool) $user->can('business_settings.access');
    }

    /**
     * Validation rules — partial update, every field is optional.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'currency_id' => ['sometimes', 'integer', 'exists:currencies,id'],
            'start_date' => ['sometimes', 'date'],
            'time_zone' => ['sometimes', 'string', 'max:64'],
            'fy_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'accounting_method' => ['sometimes', 'in:fifo,lifo,avco'],

            'tax_label_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_label_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number_2' => ['sometimes', 'nullable', 'string', 'max:255'],

            'default_profit_percent' => ['sometimes', 'numeric', 'min:0'],
            'default_sales_tax' => ['sometimes', 'nullable', 'integer', 'exists:tax_rates,id'],
            'default_sales_discount' => ['sometimes', 'numeric', 'min:0'],
            'sell_price_tax' => ['sometimes', 'in:includes,excludes'],
            'sku_prefix' => ['sometimes', 'nullable', 'string', 'max:64'],
            'transaction_edit_days' => ['sometimes', 'integer', 'min:0'],

            'currency_symbol_placement' => ['sometimes', 'in:before,after'],
            'currency_precision' => ['sometimes', 'integer', 'between:0,8'],
            'quantity_precision' => ['sometimes', 'integer', 'between:0,8'],
            'date_format' => ['sometimes', 'string', 'max:32'],
            'time_format' => ['sometimes', 'in:12,24'],
            'theme_color' => ['sometimes', 'nullable', 'string', 'max:32'],

            'enable_rp' => ['sometimes', 'boolean'],
            'rp_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount_for_unit_rp' => ['sometimes', 'numeric', 'min:0'],

            'enable_brand' => ['sometimes', 'boolean'],
            'enable_category' => ['sometimes', 'boolean'],
            'enable_sub_category' => ['sometimes', 'boolean'],
            'enable_price_tax' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Override the default Laravel 422 envelope so validation failures
     * return the canonical `validation_failed` JSON shape.
     *
     * Mirrors `App\Http\Requests\Api\LoginRequest::failedValidation`.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            JsonError::validationFailed($validator->errors()->toArray())
        );
    }
}
