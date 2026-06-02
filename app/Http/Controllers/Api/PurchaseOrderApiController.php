<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\PurchaseResource;

class PurchaseOrderApiController extends Controller
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
        if (! auth()->user()->can('purchase_order.view_all') && ! auth()->user()->can('purchase_order.view_own')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
                    ->join('business_locations AS BS', 'transactions.location_id', '=', 'BS.id')
                    ->leftJoin('users as u', 'transactions.created_by', '=', 'u.id')
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'purchase_order')
                    ->select(
                        'transactions.id',
                        'transactions.document',
                        'transactions.transaction_date',
                        'transactions.ref_no',
                        'contacts.name',
                        'contacts.supplier_business_name',
                        'transactions.status',
                        'transactions.final_total',
                        'BS.name as location_name',
                        'u.first_name as added_by'
                    )
                    ->groupBy('transactions.id');

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! auth()->user()->can('purchase_order.view_all') && auth()->user()->can('purchase_order.view_own')) {
            $query->where('transactions.created_by', auth()->user()->id);
        }

        if ($request->has('supplier_id') && !empty($request->supplier_id)) {
            $query->where('contacts.id', $request->supplier_id);
        }

        if ($request->has('location_id') && !empty($request->location_id)) {
            $query->where('transactions.location_id', $request->location_id);
        }

        if ($request->has('status') && !empty($request->status)) {
            $query->where('transactions.status', $request->status);
        }

        $purchase_orders = $query->orderBy('transactions.transaction_date', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($purchase_orders);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('purchase_order.create')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $transaction_data = $request->only(['ref_no', 'status', 'contact_id', 'transaction_date', 'total_before_tax', 'location_id', 'discount_type', 'discount_amount', 'tax_id', 'tax_amount', 'shipping_details', 'shipping_charges', 'final_total', 'additional_notes', 'exchange_rate', 'pay_term_number', 'pay_term_type']);
            
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
            $transaction_data['type'] = 'purchase_order';
            $transaction_data['payment_status'] = null; // No payments for POs
            $transaction_data['transaction_date'] = $this->productUtil->uf_date($transaction_data['transaction_date'], true);

            DB::beginTransaction();

            $ref_count = $this->productUtil->setAndGetReferenceCount($transaction_data['type']);
            if (empty($transaction_data['ref_no'])) {
                $transaction_data['ref_no'] = $this->productUtil->generateReferenceNumber($transaction_data['type'], $ref_count);
            }

            $transaction = Transaction::create($transaction_data);

            $purchases = $request->input('purchases');
            $enable_product_editing = pos_context('business.enable_editing_product_from_purchase');

            $this->productUtil->createOrUpdatePurchaseLines($transaction, $purchases, $currency_details, $enable_product_editing);

            $this->transactionUtil->activityLog($transaction, 'added');

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('lang_v1.added_success'),
                'data' => new PurchaseResource($transaction)
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to create purchase order.');
        }
    }
}
