<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ExpenseResource;
use App\ExpenseCategory;

class ExpenseApiController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $transactionUtil;

    /**
     * Constructor
     *
     * @param TransactionUtil $transactionUtil
     * @return void
     */
    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if (! auth()->user()->can('all_expense.access') && ! auth()->user()->can('view_own_expense')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = Transaction::leftJoin('expense_categories AS ec', 'transactions.expense_category_id', '=', 'ec.id')
                    ->leftJoin('expense_categories AS esc', 'transactions.expense_sub_category_id', '=', 'esc.id')
                    ->join('business_locations AS bl', 'transactions.location_id', '=', 'bl.id')
                    ->leftJoin('users AS U', 'transactions.expense_for', '=', 'U.id')
                    ->leftJoin('contacts AS c', 'transactions.contact_id', '=', 'c.id')
                    ->leftJoin('users AS usr', 'transactions.created_by', '=', 'usr.id')
                    ->where('transactions.business_id', $business_id)
                    ->whereIn('transactions.type', ['expense', 'expense_refund'])
                    ->select(
                        'transactions.id',
                        'transactions.document',
                        'transactions.transaction_date',
                        'transactions.ref_no',
                        'ec.name as category',
                        'esc.name as sub_category',
                        'transactions.payment_status',
                        'transactions.additional_notes',
                        'transactions.final_total',
                        'transactions.is_recurring',
                        'bl.name as location_name',
                        DB::raw("CONCAT(COALESCE(U.surname, ''),' ',COALESCE(U.first_name, ''),' ',COALESCE(U.last_name,'')) as expense_for"),
                        'c.name as contact_name',
                        'transactions.type',
                        DB::raw("CONCAT(COALESCE(usr.surname, ''),' ',COALESCE(usr.first_name, ''),' ',COALESCE(usr.last_name,'')) as added_by"),
                        DB::raw('(SELECT SUM(IF(TP.is_return = 1,-1*TP.amount,TP.amount)) FROM transaction_payments AS TP WHERE
                        TP.transaction_id=transactions.id) as amount_paid')
                    );

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('transactions.location_id', $permitted_locations);
        }

        if (! auth()->user()->can('all_expense.access') && auth()->user()->can('view_own_expense')) {
            $query->where('transactions.created_by', auth()->user()->id);
        }

        if ($request->has('location_id') && !empty($request->location_id)) {
            $query->where('transactions.location_id', $request->location_id);
        }

        if ($request->has('expense_for') && !empty($request->expense_for)) {
            $query->where('transactions.expense_for', $request->expense_for);
        }

        if ($request->has('contact_id') && !empty($request->contact_id)) {
            $query->where('transactions.contact_id', $request->contact_id);
        }

        if ($request->has('expense_category_id') && !empty($request->expense_category_id)) {
            $query->where('transactions.expense_category_id', $request->expense_category_id);
        }

        if ($request->has('start_date') && !empty($request->start_date)) {
            $query->whereDate('transactions.transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date') && !empty($request->end_date)) {
            $query->whereDate('transactions.transaction_date', '<=', $request->end_date);
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('transactions.payment_status', $request->payment_status);
        }

        $expenses = $query->orderBy('transactions.transaction_date', 'desc')->paginate($request->get('per_page', 20));

        return response()->json($expenses);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('expense.add')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');

            $expense_data = $request->only(['ref_no', 'expense_category_id', 'expense_sub_category_id', 'location_id', 'final_total', 'additional_notes', 'expense_for', 'contact_id']);
            $expense_data['business_id'] = $business_id;
            $expense_data['created_by'] = $user_id;
            $expense_data['type'] = 'expense';
            $expense_data['payment_status'] = 'due';

            if (!empty($request->input('transaction_date'))) {
                $expense_data['transaction_date'] = $this->transactionUtil->uf_date($request->input('transaction_date'), true);
            } else {
                $expense_data['transaction_date'] = \Carbon::now()->toDateTimeString();
            }

            $expense_data['final_total'] = $this->transactionUtil->num_uf($expense_data['final_total']);

            // Update reference count
            $ref_count = $this->transactionUtil->setAndGetReferenceCount('expense');
            if (empty($expense_data['ref_no'])) {
                $expense_data['ref_no'] = $this->transactionUtil->generateReferenceNumber('expense', $ref_count);
            }

            DB::beginTransaction();

            $expense = Transaction::create($expense_data);

            if ($request->has('payment') && is_array($request->input('payment'))) {
                $this->transactionUtil->createOrUpdatePaymentLines($expense, $request->input('payment'));
                $this->transactionUtil->updatePaymentStatus($expense->id, $expense->final_total);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('expense.expense_add_success'),
                'data' => $expense
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to create expense.');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (! auth()->user()->can('expense.edit')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');
            
            $expense = Transaction::where('business_id', $business_id)
                                ->where('id', $id)
                                ->where('type', 'expense')
                                ->firstOrFail();

            $expense_data = $request->only(['ref_no', 'expense_category_id', 'expense_sub_category_id', 'location_id', 'final_total', 'additional_notes', 'expense_for', 'contact_id']);
            
            if (!empty($request->input('transaction_date'))) {
                $expense_data['transaction_date'] = $this->transactionUtil->uf_date($request->input('transaction_date'), true);
            }

            $expense_data['final_total'] = $this->transactionUtil->num_uf($expense_data['final_total'] ?? $expense->final_total);

            DB::beginTransaction();

            $expense->update($expense_data);

            if ($request->has('payment') && is_array($request->input('payment'))) {
                $this->transactionUtil->createOrUpdatePaymentLines($expense, $request->input('payment'));
                $this->transactionUtil->updatePaymentStatus($expense->id, $expense->final_total);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('expense.expense_update_success'),
                'data' => $expense
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to update expense.');
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
        if (! auth()->user()->can('expense.delete')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');

            $expense = Transaction::where('business_id', $business_id)
                                ->where('id', $id)
                                ->whereIn('type', ['expense', 'expense_refund'])
                                ->firstOrFail();

            DB::beginTransaction();

            //Delete Cash register transactions
            $expense->cash_register_payments()->delete();

            //Delete account transactions
            \App\AccountTransaction::where('transaction_id', $expense->id)->delete();

            $expense->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('expense.expense_delete_success')
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to delete expense.');
        }
    }
}
