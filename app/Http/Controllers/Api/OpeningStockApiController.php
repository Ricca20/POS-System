<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreOpeningStockRequest;
use App\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock endpoint for the desktop SPA (task 4.4).
 *
 *   POST /api/v1/stock/opening   -> store()
 *
 * Mounted behind `auth:sanctum` + `SetSessionDataApi`. The form request
 * (`StoreOpeningStockRequest`) gates the action behind the legacy
 * `product.opening_stock` permission and constrains `product_id` and
 * `location_id` to the authenticated business.
 *
 * Opening stock semantics: `qty_available` is **set** (overwritten) for
 * the (product, variation, location) tuple, not added. Calling this
 * endpoint twice with different `qty` values yields the second value,
 * matching the legacy "initial state" semantics — opening stock is the
 * starting point a business records when first onboarding inventory and
 * is not a movement record (no `transactions` row is created).
 *
 * `qty = 0` is permitted, expressing "I started this location with no
 * stock of this variation".
 *
 * Validates: R8.1, R8.2.
 */
class OpeningStockApiController extends Controller
{
    /**
     * POST /api/v1/stock/opening
     *
     * Find-or-create the `variation_location_details` row, set
     * `qty_available` to the supplied value, and return the persisted
     * row. The lookup uses `lockForUpdate()` inside a DB transaction so
     * concurrent calls for the same tuple serialise on the row.
     */
    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        $data = $request->validated();

        $row = DB::transaction(function () use ($data) {
            $existing = VariationLocationDetails::where('product_id', (int) $data['product_id'])
                ->where('variation_id', (int) $data['variation_id'])
                ->where('location_id', (int) $data['location_id'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return VariationLocationDetails::create([
                    'product_id' => (int) $data['product_id'],
                    'variation_id' => (int) $data['variation_id'],
                    'location_id' => (int) $data['location_id'],
                    'qty_available' => (float) $data['qty'],
                ]);
            }

            $existing->qty_available = (float) $data['qty'];
            $existing->save();

            return $existing;
        });

        return response()->json(['data' => $row->fresh()], 200);
    }
}
