<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Account;
use App\AccountTransaction;
use App\Utils\Util;
use App\Http\Responses\JsonError;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\AccountResource;
use App\Http\Resources\AccountTransactionResource;

class AccountingApiController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $commonUtil;

    /**
     * Constructor
     *
     * @param Util $commonUtil
     * @return void
     */
    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = Account::leftjoin('account_transactions as AT', function ($join) {
            $join->on('AT.account_id', '=', 'accounts.id');
            $join->whereNull('AT.deleted_at');
        })
        ->leftjoin('account_types as act', 'accounts.account_type_id', '=', 'act.id')
        ->leftjoin('account_types as pat', 'act.parent_account_type_id', '=', 'pat.id')
        ->leftJoin('users as u', 'accounts.created_by', '=', 'u.id')
        ->where('accounts.business_id', $business_id)
        ->select([
            'accounts.id',
            'accounts.name',
            'accounts.account_number',
            'accounts.note',
            'accounts.account_type_id',
            'act.name as account_type_name',
            'pat.name as parent_account_type_name',
            'accounts.account_details',
            'is_closed',
            DB::raw("SUM( IF(AT.type='credit', amount, -1*amount) ) as balance"),
            DB::raw("CONCAT(COALESCE(u.surname, ''),' ',COALESCE(u.first_name, ''),' ',COALESCE(u.last_name,'')) as added_by"),
        ])
        ->groupBy('accounts.id');

        $is_closed = $request->input('account_status') == 'closed' ? 1 : 0;
        $query->where('is_closed', $is_closed);

        if ($request->has('account_type_id') && !empty($request->account_type_id)) {
            $query->where('accounts.account_type_id', $request->account_type_id);
        }

        $accounts = $query->paginate($request->get('per_page', 20));

        return AccountResource::collection($accounts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'account_number' => 'required|string|max:255',
            ]);

            $input = $request->only(['name', 'account_number', 'note', 'account_type_id', 'account_details']);
            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $input['business_id'] = $business_id;
            $input['created_by'] = $user_id;

            DB::beginTransaction();

            $account = Account::create($input);

            //Opening Balance
            $opening_bal = $request->input('opening_balance');

            if (! empty($opening_bal)) {
                $ob_transaction_data = [
                    'amount' => $this->commonUtil->num_uf($opening_bal),
                    'account_id' => $account->id,
                    'type' => 'credit',
                    'sub_type' => 'opening_balance',
                    'operation_date' => \Carbon::now(),
                    'created_by' => $user_id,
                ];

                AccountTransaction::createAccountTransaction($ob_transaction_data);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('account.account_created_success'),
                'data' => $account
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to create account.');
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
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $account = Account::where('business_id', $business_id)
            ->with(['account_type', 'account_type.parent_account'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $account
        ]);
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
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'account_number' => 'required|string|max:255',
            ]);

            $input = $request->only(['name', 'account_number', 'note', 'account_type_id', 'account_details']);
            $business_id = pos_context('business.id');

            $account = Account::where('business_id', $business_id)->findOrFail($id);
            $account->update($input);

            return response()->json([
                'success' => true,
                'msg' => __('account.account_updated_success'),
                'data' => $account
            ]);

        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to update account.');
        }
    }

    /**
     * Close the specified account.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function close($id)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        try {
            $business_id = pos_context('business.id');
            
            $account = Account::where('business_id', $business_id)->findOrFail($id);
            $account->is_closed = 1;
            $account->save();

            return response()->json([
                'success' => true,
                'msg' => __('account.account_closed_success')
            ]);
            
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to close account.');
        }
    }

    /**
     * Transfer funds between accounts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function fund_transfer(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        try {
            $request->validate([
                'from_account' => 'required|integer',
                'to_account' => 'required|integer',
                'amount' => 'required|numeric|min:0.01',
                'operation_date' => 'required|date'
            ]);

            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $amount = $this->commonUtil->num_uf($request->input('amount'));
            $from_account = $request->input('from_account');
            $to_account = $request->input('to_account');
            
            if ($from_account == $to_account) {
                return JsonError::badRequest('Cannot transfer to same account');
            }

            DB::beginTransaction();

            $transfer_transaction_data = [
                'operation_date' => $this->commonUtil->uf_date($request->input('operation_date'), true),
                'created_by' => $user_id,
            ];

            // Debit from account
            $transfer_transaction_data['amount'] = $amount;
            $transfer_transaction_data['account_id'] = $from_account;
            $transfer_transaction_data['type'] = 'debit';
            $transfer_transaction_data['sub_type'] = 'fund_transfer';
            $transfer_transaction_data['note'] = $request->input('note');
            $debit_transaction = AccountTransaction::createAccountTransaction($transfer_transaction_data);
            
            // Credit to account
            $transfer_transaction_data['account_id'] = $to_account;
            $transfer_transaction_data['type'] = 'credit';
            $transfer_transaction_data['transfer_transaction_id'] = $debit_transaction->id;
            $credit_transaction = AccountTransaction::createAccountTransaction($transfer_transaction_data);
            
            $debit_transaction->transfer_transaction_id = $credit_transaction->id;
            $debit_transaction->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('account.fund_transfered_success')
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to transfer funds.');
        }
    }

    /**
     * Deposit funds to an account.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function deposit(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        try {
            $request->validate([
                'account_id' => 'required|integer',
                'amount' => 'required|numeric|min:0.01',
                'operation_date' => 'required|date'
            ]);

            $business_id = pos_context('business.id');
            $user_id = pos_context('user.id');
            
            $amount = $this->commonUtil->num_uf($request->input('amount'));

            $deposit_data = [
                'amount' => $amount,
                'account_id' => $request->input('account_id'),
                'type' => 'credit',
                'sub_type' => 'deposit',
                'operation_date' => $this->commonUtil->uf_date($request->input('operation_date'), true),
                'created_by' => $user_id,
                'note' => $request->input('note')
            ];

            DB::beginTransaction();
            AccountTransaction::createAccountTransaction($deposit_data);
            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => __('account.deposited_successfully')
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to deposit funds.');
        }
    }

    /**
     * Get cash flow.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function cash_flow(Request $request)
    {
        if (! auth()->user()->can('account.access')) {
            return JsonError::unauthorized();
        }

        $business_id = pos_context('business.id');

        $query = AccountTransaction::join(
            'accounts as A',
            'account_transactions.account_id',
            '=',
            'A.id'
        )
        ->leftjoin(
            'transaction_payments as TP',
            'account_transactions.transaction_payment_id',
            '=',
            'TP.id'
        )
        ->leftjoin(
            'transactions as T',
            'TP.transaction_id',
            '=',
            'T.id'
        )
        ->leftjoin(
            'users as U',
            'account_transactions.created_by',
            '=',
            'U.id'
        )
        ->leftJoin(
            'contacts as c',
            'TP.payment_for',
            '=',
            'c.id'
        )
        ->where('A.business_id', $business_id)
        ->with(['transaction', 'transaction.contact', 'transfer_transaction', 'transfer_transaction.account'])
        ->select([
            'account_transactions.id',
            'account_transactions.account_id',
            'account_transactions.type',
            'account_transactions.sub_type',
            'account_transactions.operation_date',
            'account_transactions.amount',
            'account_transactions.transaction_id',
            'account_transactions.transaction_payment_id',
            'account_transactions.transfer_transaction_id',
            'account_transactions.note',
            'A.name as account_name',
            DB::raw("CONCAT(COALESCE(U.surname, ''),' ',COALESCE(U.first_name, ''),' ',COALESCE(U.last_name,'')) as added_by")
        ]);

        if ($request->has('account_id') && !empty($request->account_id)) {
            $query->where('A.id', $request->account_id);
        }

        if ($request->has('start_date') && !empty($request->start_date)) {
            $query->whereDate('account_transactions.operation_date', '>=', $request->start_date);
        }
        
        if ($request->has('end_date') && !empty($request->end_date)) {
            $query->whereDate('account_transactions.operation_date', '<=', $request->end_date);
        }

        $transactions = $query->orderBy('account_transactions.operation_date', 'desc')->paginate($request->get('per_page', 20));

        // Calculate running balance per account if filtering by single account
        if ($request->has('account_id') && !empty($request->account_id)) {
            $balance = 0;
            // A more complex query is needed to calculate historical running balance accurately.
            // For now, we rely on the client to calculate running balance or provide raw data.
        }

        return AccountTransactionResource::collection($transactions);
    }
}
