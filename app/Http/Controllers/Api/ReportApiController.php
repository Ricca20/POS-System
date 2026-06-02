<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Utils\TransactionUtil;
use App\Utils\ProductUtil;
use App\Utils\ModuleUtil;
use App\Utils\BusinessUtil;
use App\Http\Responses\JsonError;
use App\Contact;
use App\Transaction;
use App\ExpenseCategory;
use App\PurchaseLine;
use App\TaxRate;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Carbon\Carbon;

class ReportApiController extends Controller
{
    protected $transactionUtil;
    protected $productUtil;
    protected $moduleUtil;
    protected $businessUtil;

    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil, ModuleUtil $moduleUtil, BusinessUtil $businessUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
        $this->moduleUtil = $moduleUtil;
        $this->businessUtil = $businessUtil;
    }

    public function profit_loss(Request $request)
    {
        if (! auth()->user()->can('profit_loss_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $fy = $this->businessUtil->getCurrentFinancialYear($business_id);

        $location_id = $request->input('location_id') ?? null;
        $start_date = $request->input('start_date') ?? $fy['start'];
        $end_date = $request->input('end_date') ?? $fy['end'];
        $user_id = $request->input('user_id') ?? null;

        $permitted_locations = auth()->user()->permitted_locations();
        
        $data = $this->transactionUtil->getProfitLossDetails($business_id, $location_id, $start_date, $end_date, $user_id, $permitted_locations);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function purchase_sell(Request $request)
    {
        if (! auth()->user()->can('purchase_n_sell_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $start_date = $request->get('start_date');
        $end_date = $request->get('end_date');
        $location_id = $request->get('location_id');

        $purchase_details = $this->transactionUtil->getPurchaseTotals($business_id, $start_date, $end_date, $location_id);
        $sell_details = $this->transactionUtil->getSellTotals($business_id, $start_date, $end_date, $location_id);

        $transaction_types = ['purchase_return', 'sell_return'];
        $transaction_totals = $this->transactionUtil->getTransactionTotals(
            $business_id,
            $transaction_types,
            $start_date,
            $end_date,
            $location_id
        );

        $total_purchase_return_inc_tax = $transaction_totals['total_purchase_return_inc_tax'];
        $total_sell_return_inc_tax = $transaction_totals['total_sell_return_inc_tax'];

        $difference = [
            'total' => $sell_details['total_sell_inc_tax'] - $total_sell_return_inc_tax - ($purchase_details['total_purchase_inc_tax'] - $total_purchase_return_inc_tax),
            'due' => $sell_details['invoice_due'] - $purchase_details['purchase_due'],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'purchase' => $purchase_details,
                'sell' => $sell_details,
                'total_purchase_return' => $total_purchase_return_inc_tax,
                'total_sell_return' => $total_sell_return_inc_tax,
                'difference' => $difference,
            ]
        ]);
    }

    public function stock_report(Request $request)
    {
        if (! auth()->user()->can('stock_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        
        $filters = $request->only(['location_id', 'category_id', 'sub_category_id', 'brand_id', 'unit_id', 'tax_id', 'type',
            'only_mfg_products', 'active_state', 'not_for_selling', 'repair_model_id', 'product_id', 'active_state']);

        $filters['not_for_selling'] = isset($filters['not_for_selling']) && $filters['not_for_selling'] == 'true' ? 1 : 0;
        
        $show_manufacturing_data = 0;
        if ($this->moduleUtil->isModuleInstalled('Manufacturing') && (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'manufacturing_module'))) {
            $show_manufacturing_data = 1;
        }
        $filters['show_manufacturing_data'] = $show_manufacturing_data;

        $products = $this->productUtil->getProductStockDetails($business_id, $filters, 'datatables');
        
        $per_page = $request->get('per_page', 20);
        $paginated_products = collect($products->get())->paginate($per_page);

        return response()->json([
            'success' => true,
            'data' => $paginated_products
        ]);
    }

    public function trending_products(Request $request)
    {
        if (! auth()->user()->can('trending_product_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $filters = $request->only(['category', 'sub_category', 'brand', 'unit', 'limit', 'location_id', 'product_type', 'start_date', 'end_date']);

        $products = $this->productUtil->getTrendingProducts($business_id, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function expense_report(Request $request)
    {
        if (! auth()->user()->can('expense_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $filters = $request->only(['category', 'location_id', 'start_date', 'end_date']);

        if (empty($filters['start_date'])) {
            $filters['start_date'] = \Carbon::now()->startOfMonth()->format('Y-m-d');
            $filters['end_date'] = \Carbon::now()->endOfMonth()->format('Y-m-d');
        }

        $expenses = $this->transactionUtil->getExpenseReport($business_id, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $expenses
        ]);
    }

    public function register_report(Request $request)
    {
        if (! auth()->user()->can('register_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        $user_id = $request->input('user_id');

        $permitted_locations = auth()->user()->permitted_locations();
        $registers = $this->transactionUtil->registerReport($business_id, $permitted_locations, $start_date, $end_date, $user_id);
        
        $per_page = $request->get('per_page', 20);
        $paginated_registers = $registers->paginate($per_page);

        return response()->json([
            'success' => true,
            'data' => $paginated_registers
        ]);
    }

    public function sales_representative_report(Request $request)
    {
        if (! auth()->user()->can('sales_representative.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $start_date = $request->get('start_date');
        $end_date = $request->get('end_date');
        $location_id = $request->get('location_id');
        $created_by = $request->get('created_by');

        $sell_details = $this->transactionUtil->getSellTotals($business_id, $start_date, $end_date, $location_id, $created_by);

        $transaction_types = ['sell_return'];
        $sell_return_details = $this->transactionUtil->getTransactionTotals(
            $business_id,
            $transaction_types,
            $start_date,
            $end_date,
            $location_id,
            $created_by
        );

        $total_sell_return_inc_tax = $sell_return_details['total_sell_return_inc_tax'];

        return response()->json([
            'success' => true,
            'data' => [
                'sell' => $sell_details,
                'total_sell_return' => $total_sell_return_inc_tax
            ]
        ]);
    }

    public function stock_expiry_report(Request $request)
    {
        if (! auth()->user()->can('stock_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = PurchaseLine::leftjoin(
            'transactions as t',
            'purchase_lines.transaction_id',
            '=',
            't.id'
        )
        ->leftjoin(
            'products as p',
            'purchase_lines.product_id',
            '=',
            'p.id'
        )
        ->leftjoin(
            'variations as v',
            'purchase_lines.variation_id',
            '=',
            'v.id'
        )
        ->leftjoin(
            'product_variations as pv',
            'v.product_variation_id',
            '=',
            'pv.id'
        )
        ->leftjoin('business_locations as l', 't.location_id', '=', 'l.id')
        ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
        ->where('t.business_id', $business_id)
        ->whereNotNull('p.expiry_period')
        ->whereNotNull('p.expiry_period_type')
        ->where('t.type', 'purchase')
        ->where('t.status', 'received')
        ->where('p.is_inactive', 0);

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('t.location_id', $permitted_locations);
        }

        if ($request->has('location_id') && !empty($request->location_id)) {
            $query->where('t.location_id', $request->location_id);
        }

        if ($request->has('category_id') && !empty($request->category_id)) {
            $query->where('p.category_id', $request->category_id);
        }

        if ($request->has('brand_id') && !empty($request->brand_id)) {
            $query->where('p.brand_id', $request->brand_id);
        }

        $report = $query->select(
            'p.name as product',
            'p.sku',
            'p.type as product_type',
            'v.name as variation',
            'pv.name as product_variation',
            'l.name as location',
            'mfg_date',
            'exp_date',
            'u.short_name as unit',
            DB::raw("SUM(quantity - quantity_sold - quantity_adjusted - quantity_returned) as stock_left"),
            't.ref_no',
            't.id as transaction_id',
            'purchase_lines.id as purchase_line_id',
            'purchase_lines.lot_number'
        )
        ->having('stock_left', '>', 0)
        ->groupBy('purchase_lines.id');

        $per_page = $request->get('per_page', 20);
        $paginated_report = $report->paginate($per_page);

        return response()->json([
            'success' => true,
            'data' => $paginated_report
        ]);
    }

    public function customer_supplier(Request $request)
    {
        if (! auth()->user()->can('contacts_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $contacts = Contact::where('contacts.business_id', $business_id)
            ->join('transactions AS t', 'contacts.id', '=', 't.contact_id')
            ->active()
            ->groupBy('contacts.id')
            ->select(
                DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                DB::raw("SUM(IF(t.type = 'purchase_return', final_total, 0)) as total_purchase_return"),
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', final_total, 0)) as total_invoice"),
                DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_received"),
                DB::raw("SUM(IF(t.type = 'sell_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as sell_return_paid"),
                DB::raw("SUM(IF(t.type = 'purchase_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_return_received"),
                DB::raw("SUM(IF(t.type = 'sell_return', final_total, 0)) as total_sell_return"),
                DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid"),
                DB::raw("SUM(IF(t.type = 'ledger_discount' AND sub_type='sell_discount', final_total, 0)) as total_ledger_discount_sell"),
                DB::raw("SUM(IF(t.type = 'ledger_discount' AND sub_type='purchase_discount', final_total, 0)) as total_ledger_discount_purchase"),
                'contacts.supplier_business_name',
                'contacts.name',
                'contacts.id',
                'contacts.type as contact_type'
            );
            
        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $contacts->whereIn('t.location_id', $permitted_locations);
        }

        if (! empty($request->input('customer_group_id'))) {
            $contacts->where('contacts.customer_group_id', $request->input('customer_group_id'));
        }

        if (! empty($request->input('location_id'))) {
            $contacts->where('t.location_id', $request->input('location_id'));
        }

        if (! empty($request->input('contact_id'))) {
            $contacts->where('t.contact_id', $request->input('contact_id'));
        }

        if (! empty($request->input('contact_type'))) {
            $contacts->whereIn('contacts.type', [$request->input('contact_type'), 'both']);
        }

        $start_date = $request->get('start_date');
        $end_date = $request->get('end_date');
        if (! empty($start_date) && ! empty($end_date)) {
            $contacts->where('t.transaction_date', '>=', $start_date)
                ->where('t.transaction_date', '<=', $end_date);
        }

        $per_page = $request->get('per_page', 20);
        $paginated_contacts = $contacts->paginate($per_page);

        // Calculate due amounts on the fly for each item
        $paginated_contacts->getCollection()->transform(function ($row) {
            $total_ledger_discount_purchase = $row->total_ledger_discount_purchase ?? 0;
            $total_ledger_discount_sell = $row->total_ledger_discount_sell ?? 0;
            $due = ($row->total_invoice - $row->invoice_received - $total_ledger_discount_sell) - ($row->total_purchase - $row->purchase_paid - $total_ledger_discount_purchase) - ($row->total_sell_return - $row->sell_return_paid) + ($row->total_purchase_return - $row->purchase_return_received);

            if ($row->contact_type == 'supplier') {
                $due -= $row->opening_balance - $row->opening_balance_paid;
            } else {
                $due += $row->opening_balance - $row->opening_balance_paid;
            }
            
            $row->due = $due;
            $row->opening_balance_due = $row->opening_balance - $row->opening_balance_paid;
            return $row;
        });

        return response()->json([
            'success' => true,
            'data' => $paginated_contacts
        ]);
    }

    public function tax_report(Request $request)
    {
        if (! auth()->user()->can('tax_report.view')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');
        $type = $request->input('type', 'sell');

        $sells = Transaction::leftJoin('tax_rates as tr', 'transactions.tax_id', '=', 'tr.id')
            ->leftJoin('contacts as c', 'transactions.contact_id', '=', 'c.id')
            ->where('transactions.business_id', $business_id)
            ->with(['payment_lines'])
            ->select('c.name as contact_name',
                    'c.supplier_business_name',
                    'c.tax_number',
                    'transactions.ref_no',
                    'transactions.invoice_no',
                    'transactions.transaction_date',
                    'transactions.total_before_tax',
                    'transactions.tax_id',
                    'transactions.tax_amount',
                    'transactions.id',
                    'transactions.type',
                    'transactions.discount_type',
                    'transactions.discount_amount'
                );

        if ($type == 'sell') {
            $sells->where('transactions.type', 'sell')
                ->where('transactions.status', 'final')
                ->where(function ($query) {
                    $query->whereHas('sell_lines', function ($q) {
                        $q->whereNotNull('transaction_sell_lines.tax_id');
                    })->orWhereNotNull('transactions.tax_id');
                })
                ->with(['sell_lines' => function ($q) {
                    $q->whereNotNull('transaction_sell_lines.tax_id');
                }, 'sell_lines.line_tax']);
        }
        if ($type == 'purchase') {
            $sells->where('transactions.type', 'purchase')
                ->where('transactions.status', 'received')
                ->where(function ($query) {
                    $query->whereHas('purchase_lines', function ($q) {
                        $q->whereNotNull('purchase_lines.tax_id');
                    })->orWhereNotNull('transactions.tax_id');
                })
                ->with(['purchase_lines' => function ($q) {
                    $q->whereNotNull('purchase_lines.tax_id');
                }, 'purchase_lines.line_tax']);
        }
        if ($type == 'expense') {
            $sells->where('transactions.type', 'expense')
                    ->whereNotNull('transactions.tax_id');
        }

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $sells->whereIn('transactions.location_id', $permitted_locations);
        }

        if (request()->has('location_id')) {
            $location_id = request()->get('location_id');
            if (! empty($location_id)) {
                $sells->where('transactions.location_id', $location_id);
            }
        }

        if (request()->has('contact_id')) {
            $contact_id = request()->get('contact_id');
            if (! empty($contact_id)) {
                $sells->where('transactions.contact_id', $contact_id);
            }
        }

        if (! empty(request()->start_date) && ! empty(request()->end_date)) {
            $start = request()->start_date;
            $end = request()->end_date;
            $sells->whereDate('transactions.transaction_date', '>=', $start)
                            ->whereDate('transactions.transaction_date', '<=', $end);
        }
        
        $per_page = $request->get('per_page', 20);
        $paginated_sells = $sells->paginate($per_page);

        return response()->json([
            'success' => true,
            'data' => $paginated_sells
        ]);
    }

    public function activity_log(Request $request)
    {
        $business_id = pos_context('business.id');

        $query = Activity::where('business_id', $business_id)
                        ->with(['causer', 'subject']);

        if (!empty($request->input('subject_type'))) {
            if ($request->input('subject_type') == 'App\Transaction') {
                $query->where('subject_type', 'App\Transaction')
                    ->join('transactions', 'activity_log.subject_id', '=', 'transactions.id');

                if (!empty($request->input('transaction_type'))) {
                    $query->where('transactions.type', $request->input('transaction_type'));
                }
            } else {
                $query->where('subject_type', $request->input('subject_type'));
            }
        }

        if (!empty($request->input('user_id'))) {
            $query->where('causer_id', $request->input('user_id'));
        }

        if (!empty($request->input('start_date')) && !empty($request->input('end_date'))) {
            $query->whereDate('activity_log.created_at', '>=', $request->input('start_date'))
                ->whereDate('activity_log.created_at', '<=', $request->input('end_date'));
        }

        $per_page = $request->get('per_page', 20);
        $activities = $query->latest()->paginate($per_page);

        return response()->json([
            'success' => true,
            'data' => $activities
        ]);
    }
}
