<?php

namespace App\Http\Requests\Api;

use App\Http\Responses\JsonError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form request for `POST /api/v1/stock/adjustment` (task 4.4).
 *
 * Validates: R8.1, R8.2.
 *
 * Authorization mirrors the legacy `StockAdjustmentController::store`
 * (line 159), which gates stock adjustments behind the `purchase.create`
 * Spatie permission. The mapping is counter-intuitive but is the existing
 * codebase convention — stock adjustments share the same role as
 * purchases for permission purposes.
 *
 * The `location_id` is constrained via `Rule::exists` scoped to the
 * authenticated user's business so cross-business writes never reach the
 * controller and short-circuit with a 422 carrying `errors.location_id`.
 */
class StoreStockAdjustmentRequest extends FormRequest
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

        return (bool) $user->can('purchase.create');
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
            'ref_no' => ['sometimes', 'nullable', 'string', 'max:191'],
            'transaction_date' => ['required', 'date'],
            'adjustment_type' => ['required', 'in:normal,abnormal'],
            'additional_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer'],
            'products.*.variation_id' => ['required', 'integer'],
            'products.*.quantity' => ['required', 'numeric', 'gt:0'],
            'products.*.unit_price' => ['required', 'numeric', 'min:0'],
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
