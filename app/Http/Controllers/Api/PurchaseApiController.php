<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Api\StorePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\BusinessLocation;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use App\Events\PurchaseCreatedOrModified;

class PurchaseApiController extends Controller
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
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if (! auth()->user()->can('purchase.view') && ! auth()->user()->can('view_own_purchase')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
                    ->join(
                        'business_locations AS BS',
                        'transactions.location_id',
                        '=',
                        'BS.id'
                    )
                    ->leftJoin(
                        'transaction_payments AS TP',
                        'transactions.id',
                        '=',
                        'TP.transaction_id'
                    )
                    ->leftJoin(
                        'transactions AS PR',
                        'transactions.id',
                        '=',
                        'PR.return_parent_id'
                    )
                    ->leftJoin('users as u', 'transactions.created_by', '=', 'u.id')
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'purchase')
                    ->select(
                        'transactions.id',
                        'transactions.document',
                        'transactions.transaction_date',
                        'transactions.ref_no',
                        'contacts.name',
                        'contacts.supplier_business_name',
                        'transactions.status',
                        'transactions.payment_status',
                        'transactions.final_total',
                        'BS.name as location_name',
                        'transactions.pay_term_number',
                        'transactions.pay_term_type',
                        'PR.id as return_transaction_id',
                        'PR.amount_return as amount_return',
                        'PR.return_paid as return_paid',
                        DB::raw('SUM(TP.amount) as amount_paid'),
                        DB::raw('(SELECT SUM(TP2.amount) FROM transaction_payments AS TP2 WHERE
                        TP2.transaction_id=PR.id ) as return_paid'),
                        DB::raw('COUNT(PR.id) as return_exists'),
                        DB::raw('COALESCE(PR.status, "cancel") as return_status'),
                        'u.first_name as added_by'
                    )
                    ->groupBy('transactions.id');

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! auth()->user()->can('purchase.view') && auth()->user()->can('view_own_purchase')) {
            $query->where('transactions.created_by', auth()->user()->id);
        }

        if ($request->has('supplier_id') && !empty($request->supplier_id)) {
            $query->where('contacts.id', $request->supplier_id);
        }

        if ($request->has('location_id') && !empty($request->location_id)) {
            $query->where('transactions.location_id', $request->location_id);
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('transactions.payment_status', $request->payment_status);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('transactions.status', $request->status);
        }

        if ($request->has('start_date') && !empty($request->start_date)) {
            $query->whereDate('transactions.transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date') && !empty($request->end_date)) {
            $query->whereDate('transactions.transaction_date', '<=', $request->end_date);
        }

        $purchases = $query->orderBy('transactions.transaction_date', 'desc')->paginate($request->get('per_page', 20));

        return PurchaseResource::collection($purchases);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  StorePurchaseRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StorePurchaseRequest $request)
    {
        if (! auth()->user()->can('purchase.create')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $transaction_data = $request->only(['ref_no', 'status', 'contact_id', 'transaction_date', 'total_before_tax', 'location_id', 'discount_type', 'discount_amount', 'tax_id', 'tax_amount', 'shipping_details', 'shipping_charges', 'final_total', 'additional_notes', 'exchange_rate', 'pay_term_number', 'pay_term_type', 'purchase_order_ids']);
            
            $exchange_rate = $transaction_data['exchange_rate'] ?? 1;

            $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);

            //unformat input values
            $transaction_data['total_before_tax'] = $this->productUtil->num_uf($transaction_data['total_before_tax'] ?? 0, $currency_details) * $exchange_rate;

            if (isset($transaction_data['discount_type']) && $transaction_data['discount_type'] == 'fixed') {
                $transaction_data['discount_amount'] = $this->productUtil->num_uf($transaction_data['discount_amount'] ?? 0, $currency_details) * $exchange_rate;
            } elseif (isset($transaction_data['discount_type']) && $transaction_data['discount_type'] == 'percentage') {
                $transaction_data['discount_amount'] = $this->productUtil->num_uf($transaction_data['discount_amount'] ?? 0, $currency_details);
            } else {
                $transaction_data['discount_amount'] = 0;
            }

            $transaction_data['tax_amount'] = $this->productUtil->num_uf($transaction_data['tax_amount'] ?? 0, $currency_details) * $exchange_rate;
            $transaction_data['shipping_charges'] = $this->productUtil->num_uf($transaction_data['shipping_charges'] ?? 0, $currency_details) * $exchange_rate;
            $transaction_data['final_total'] = $this->productUtil->num_uf($transaction_data['final_total'] ?? 0, $currency_details) * $exchange_rate;

            $transaction_data['business_id'] = $business_id;
            $transaction_data['created_by'] = $user_id;
            $transaction_data['type'] = 'purchase';
            $transaction_data['payment_status'] = 'due';
            $transaction_data['transaction_date'] = $this->productUtil->uf_date($transaction_data['transaction_date'], true);

            $transaction_data['custom_field_1'] = $request->input('custom_field_1', null);
            $transaction_data['custom_field_2'] = $request->input('custom_field_2', null);
            $transaction_data['custom_field_3'] = $request->input('custom_field_3', null);
            $transaction_data['custom_field_4'] = $request->input('custom_field_4', null);

            DB::beginTransaction();

            $ref_count = $this->productUtil->setAndGetReferenceCount($transaction_data['type']);
            if (empty($transaction_data['ref_no'])) {
                $transaction_data['ref_no'] = $this->productUtil->generateReferenceNumber($transaction_data['type'], $ref_count);
            }

            $transaction = Transaction::create($transaction_data);

            $purchases = $request->input('purchases');
            $enable_product_editing = pos_context('business.enable_editing_product_from_purchase');

            $this->productUtil->createOrUpdatePurchaseLines($transaction, $purchases, $currency_details, $enable_product_editing);

            //Add Purchase payments
            if ($request->has('payment') && is_array($request->input('payment'))) {
                $this->transactionUtil->createOrUpdatePaymentLines($transaction, $request->input('payment'));
            }

            //update payment status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            if (! empty($transaction->purchase_order_ids)) {
                $this->transactionUtil->updatePurchaseOrderStatus($transaction->purchase_order_ids);
            }

            //Adjust stock over selling if found
            $this->productUtil->adjustStockOverSelling($transaction);

            $this->transactionUtil->activityLog($transaction, 'added');

            PurchaseCreatedOrModified::dispatch($transaction);

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('purchase.purchase_add_success'),
                'data' => new PurchaseResource($transaction)
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to create purchase.');
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (! auth()->user()->can('purchase.view') && ! auth()->user()->can('view_own_purchase')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        
        $query = Transaction::where('business_id', $business_id)
                    ->where('id', $id)
                    ->where('type', 'purchase')
                    ->with(
                        'contact',
                        'purchase_lines',
                        'purchase_lines.product',
                        'purchase_lines.product.unit',
                        'purchase_lines.product.second_unit',
                        'purchase_lines.variations',
                        'purchase_lines.variations.product_variation',
                        'purchase_lines.sub_unit',
                        'location',
                        'payment_lines',
                        'tax'
                    );
                    
        if (! auth()->user()->can('purchase.view') && auth()->user()->can('view_own_purchase')) {
            $query->where('created_by', auth()->user()->id);
        }

        $purchase = $query->first();
        
        if (!$purchase) {
            return JsonError::notFound('Purchase not found');
        }

        foreach ($purchase->purchase_lines as $key => $value) {
            if (! empty($value->sub_unit_id)) {
                $formated_purchase_line = $this->productUtil->changePurchaseLineUnit($value, $business_id);
                $purchase->purchase_lines[$key] = $formated_purchase_line;
            }
        }

        return new PurchaseResource($purchase);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('purchase.update')) {
            return JsonError::unauthorized();
        }

        try {
            $transaction = Transaction::findOrFail($id);
            $business_id = pos_context('business.id');
            
            // Validate transaction edit window
            $transaction_edit_days = pos_context('business.transaction_edit_days');
            if ($this->transactionUtil->canBeEdited($transaction, $transaction_edit_days) == false) {
                return JsonError::forbidden(__('messages.transaction_edit_not_allowed', ['days' => $transaction_edit_days]));
            }

            // Since updating a purchase is complex (involving purchase lines and stock),
            // we will mirror the logic but simplified for API.
            $before_status = $transaction->status;

            $transaction_data = $request->only(['ref_no', 'status', 'contact_id', 'transaction_date', 'total_before_tax', 'location_id', 'discount_type', 'discount_amount', 'tax_id', 'tax_amount', 'shipping_details', 'shipping_charges', 'final_total', 'additional_notes', 'exchange_rate', 'pay_term_number', 'pay_term_type', 'purchase_order_ids']);
            
            $exchange_rate = $transaction_data['exchange_rate'] ?? 1;
            $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);
            
            $transaction_data['total_before_tax'] = $this->productUtil->num_uf($transaction_data['total_before_tax'] ?? 0, $currency_details) * $exchange_rate;

            if (isset($transaction_data['discount_type']) && $transaction_data['discount_type'] == 'fixed') {
                $transaction_data['discount_amount'] = $this->productUtil->num_uf($transaction_data['discount_amount'] ?? 0, $currency_details) * $exchange_rate;
            } elseif (isset($transaction_data['discount_type']) && $transaction_data['discount_type'] == 'percentage') {
                $transaction_data['discount_amount'] = $this->productUtil->num_uf($transaction_data['discount_amount'] ?? 0, $currency_details);
            } else {
                $transaction_data['discount_amount'] = 0;
            }

            $transaction_data['tax_amount'] = $this->productUtil->num_uf($transaction_data['tax_amount'] ?? 0, $currency_details) * $exchange_rate;
            $transaction_data['shipping_charges'] = $this->productUtil->num_uf($transaction_data['shipping_charges'] ?? 0, $currency_details) * $exchange_rate;
            $transaction_data['final_total'] = $this->productUtil->num_uf($transaction_data['final_total'] ?? 0, $currency_details) * $exchange_rate;
            
            $transaction_data['transaction_date'] = $this->productUtil->uf_date($transaction_data['transaction_date'] ?? $transaction->transaction_date, true);
            
            DB::beginTransaction();
            
            $transaction->update($transaction_data);
            
            $purchases = $request->input('purchases');
            
            if ($purchases) {
                $enable_product_editing = pos_context('business.enable_editing_product_from_purchase');
                //Update purchase lines
                $deleted_purchase_lines = $this->productUtil->createOrUpdatePurchaseLines($transaction, $purchases, $currency_details, $enable_product_editing, $before_status);
                
                //Update mapping of purchase & Sell.
                $this->transactionUtil->adjustMappingPurchaseSellAfterEditingPurchase($before_status, $transaction, $deleted_purchase_lines);
            }
            
            if (! empty($transaction->purchase_order_ids)) {
                $this->transactionUtil->updatePurchaseOrderStatus($transaction->purchase_order_ids);
            }
            
            //Adjust stock over selling if found
            $this->productUtil->adjustStockOverSelling($transaction);

            $this->transactionUtil->activityLog($transaction, 'edited', $before_status);

            PurchaseCreatedOrModified::dispatch($transaction);

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('purchase.purchase_update_success'),
                'data' => new PurchaseResource($transaction)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to update purchase.');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (! auth()->user()->can('purchase.delete')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');

            $transaction = Transaction::where('id', $id)
                                ->where('business_id', $business_id)
                                ->where('type', 'purchase')
                                ->with(['purchase_lines'])
                                ->first();
                                
            if (!$transaction) {
                return JsonError::notFound('Purchase not found');
            }

            // Check if return exist then not allowed
            if ($this->transactionUtil->isReturnExist($id)) {
                return JsonError::badRequest(__('lang_v1.return_exist'));
            }

            DB::beginTransaction();

            $deleted_purchase_lines = $transaction->purchase_lines;
            foreach ($deleted_purchase_lines as $purchase_line) {
                if ($purchase_line->quantity_returned > 0) {
                    DB::rollBack();
                    return JsonError::badRequest(__('lang_v1.return_exist'));
                }
            }

            $transaction_status = $transaction->status;
            if ($transaction_status != 'received') {
                $transaction->delete();
            } else {
                // Delete purchase lines first
                $delete_purchase_line_ids = [];
                foreach ($deleted_purchase_lines as $purchase_line) {
                    $delete_purchase_line_ids[] = $purchase_line->id;
                    
                    if ($transaction_status == 'received') {
                        $this->productUtil->decreaseProductQuantity(
                            $purchase_line->product_id,
                            $purchase_line->variation_id,
                            $transaction->location_id,
                            $purchase_line->quantity
                        );
                    }
                }
                
                \App\PurchaseLine::where('transaction_id', $transaction->id)
                            ->whereIn('id', $delete_purchase_line_ids)
                            ->delete();

                // Delete Transaction
                $transaction->delete();
                
                $this->transactionUtil->adjustMappingPurchaseSellAfterEditingPurchase($transaction_status, $transaction, $deleted_purchase_lines);
            }

            // Delete account transactions
            \App\AccountTransaction::where('transaction_id', $id)->delete();

            $this->transactionUtil->activityLog($transaction, 'deleted');

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('purchase.purchase_delete_success')
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to delete purchase.');
        }
    }
}
