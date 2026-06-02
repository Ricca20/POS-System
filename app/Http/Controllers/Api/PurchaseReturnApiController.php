<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\PurchaseLine;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\PurchaseResource;

class PurchaseReturnApiController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $productUtil;
    protected $transactionUtil;

    /**
     * Constructor
     *
     * @param ProductUtil $productUtil
     * @param TransactionUtil $transactionUtil
     * @return void
     */
    public function __construct(ProductUtil $productUtil, TransactionUtil $transactionUtil)
    {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('purchase.update')) {
            return JsonError::unauthorized();
        }

        try {
            DB::beginTransaction();

            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $purchase = Transaction::where('business_id', $business_id)
                                ->where('type', 'purchase')
                                ->with(['purchase_lines', 'purchase_lines.product', 'purchase_lines.product.unit'])
                                ->findOrFail($request->input('transaction_id'));

            $return_quantities = $request->input('returns');
            $return_total = 0;

            foreach ($purchase->purchase_lines as $purchase_line) {
                $return_qty = !empty($return_quantities[$purchase_line->id]) ? $this->productUtil->num_uf($return_quantities[$purchase_line->id]) : 0;
                $purchase_line->quantity_returned = $return_qty;
                $purchase_line->save();
                
                if ($return_qty > 0) {
                    $return_total += $return_qty * $purchase_line->purchase_price_inc_tax;
                    
                    // Decrease stock
                    $this->productUtil->decreaseProductQuantity(
                        $purchase_line->product_id,
                        $purchase_line->variation_id,
                        $purchase->location_id,
                        $return_qty,
                        0,
                        'purchase_return'
                    );
                }
            }
            
            $tax_amount = 0;
            if (!empty($request->input('tax_id'))) {
                $tax_amount = $this->transactionUtil->calc_percentage($return_total, $request->input('tax_amount'), $request->input('tax_amount'));
            }
            $return_total_inc_tax = $return_total + $tax_amount;

            $return_transaction_data = [
                'business_id' => $business_id,
                'location_id' => $purchase->location_id,
                'type' => 'purchase_return',
                'status' => 'final',
                'contact_id' => $purchase->contact_id,
                'transaction_date' => \Carbon::now()->toDateTimeString(),
                'created_by' => $user_id,
                'return_parent_id' => $purchase->id,
                'total_before_tax' => $return_total,
                'tax_id' => $request->input('tax_id'),
                'tax_amount' => $tax_amount,
                'final_total' => $return_total_inc_tax,
                'payment_status' => 'due'
            ];

            $ref_count = $this->productUtil->setAndGetReferenceCount('purchase_return');
            if (empty($request->input('ref_no'))) {
                $return_transaction_data['ref_no'] = $this->productUtil->generateReferenceNumber('purchase_return', $ref_count);
            } else {
                $return_transaction_data['ref_no'] = $request->input('ref_no');
            }

            $return_transaction = Transaction::create($return_transaction_data);

            $this->transactionUtil->updatePaymentStatus($return_transaction->id, $return_transaction->final_total);

            // Update parent payment status
            $this->transactionUtil->updatePaymentStatus($purchase->id, $purchase->final_total);

            $this->transactionUtil->activityLog($return_transaction, 'added');

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('lang_v1.purchase_return_added_success'),
                'data' => new PurchaseResource($return_transaction)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to create purchase return.');
        }
    }
}
