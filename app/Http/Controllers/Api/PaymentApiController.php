<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\JsonError;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentApiController extends Controller
{
    protected $transactionUtil;

    public function __construct(TransactionUtil $transactionUtil)
    {
        $this->transactionUtil = $transactionUtil;
    }

    /**
     * Store a payment for a specific transaction.
     */
    public function store(StorePaymentRequest $request)
    {
        try {
            $business_id = pos_context('business.id');
            
            $transaction_id = $request->input('transaction_id');
            $transaction = Transaction::where('business_id', $business_id)->with(['contact'])->findOrFail($transaction_id);

            // Verify permission
            if (! (auth()->user()->can('purchase.payments') || auth()->user()->can('sell.payments') || auth()->user()->can('all_expense.access') || auth()->user()->can('view_own_expense'))) {
                return JsonError::forbidden();
            }

            if ($transaction->payment_status == 'paid') {
                return JsonError::make(400, 'already_paid', 'This transaction is already paid in full.');
            }

            $inputs = $request->validated();
            $inputs['paid_on'] = $this->transactionUtil->uf_date($request->input('paid_on', now()->toDateTimeString()), true);
            $inputs['transaction_id'] = $transaction->id;
            $inputs['amount'] = $this->transactionUtil->num_uf($inputs['amount']);
            $inputs['created_by'] = auth()->id();
            $inputs['payment_for'] = $transaction->contact_id;

            $prefix_type = 'purchase_payment';
            if (in_array($transaction->type, ['sell', 'sell_return'])) {
                $prefix_type = 'sell_payment';
            } elseif (in_array($transaction->type, ['expense', 'expense_refund'])) {
                $prefix_type = 'expense_payment';
            }

            DB::beginTransaction();

            $ref_count = $this->transactionUtil->setAndGetReferenceCount($prefix_type);
            $inputs['payment_ref_no'] = $this->transactionUtil->generateReferenceNumber($prefix_type, $ref_count);
            $inputs['business_id'] = $business_id;

            // Handle advance payment validation
            $contact_balance = $transaction->contact ? $transaction->contact->balance : 0;
            if ($inputs['method'] == 'advance' && $inputs['amount'] > $contact_balance) {
                return JsonError::make(400, 'insufficient_advance', __('lang_v1.required_advance_balance_not_available'));
            }

            if (!empty($inputs['amount'])) {
                $tp = TransactionPayment::create($inputs);
                
                // Fire standard event (internal calculations)
                $inputs['transaction_type'] = $transaction->type;
                event(new \App\Events\TransactionPaymentAdded($tp, $inputs));
            }

            // Update payment status
            $payment_status = $this->transactionUtil->updatePaymentStatus($transaction_id, $transaction->final_total);
            $transaction->payment_status = $payment_status;

            DB::commit();

            return new PaymentResource($tp);

        } catch (\Exception $e) {
            DB::rollBack();
            return JsonError::serverError($e->getMessage());
        }
    }

    /**
     * Display a specific payment.
     */
    public function show($id)
    {
        $business_id = pos_context('business.id');
        $payment = TransactionPayment::where('business_id', $business_id)->find($id);

        if (!$payment) {
            return JsonError::notFound('Payment not found.');
        }

        if (! (auth()->user()->can('sell.payments') || auth()->user()->can('purchase.payments'))) {
            return JsonError::forbidden();
        }

        return new PaymentResource($payment);
    }
    
    /**
     * Delete a payment.
     */
    public function destroy($id)
    {
        $business_id = pos_context('business.id');
        $payment = TransactionPayment::where('business_id', $business_id)->find($id);

        if (!$payment) {
            return JsonError::notFound('Payment not found.');
        }

        if (! (auth()->user()->can('delete_sell_payment') || auth()->user()->can('delete_purchase_payment'))) {
            return JsonError::forbidden();
        }
        
        try {
            DB::beginTransaction();

            $transaction_id = $payment->transaction_id;
            
            event(new \App\Events\TransactionPaymentDeleted($payment->id, $payment->account_id));
            $payment->delete();

            if (!empty($transaction_id)) {
                $transaction = Transaction::find($transaction_id);
                $this->transactionUtil->updatePaymentStatus($transaction_id, $transaction->final_total);
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Payment deleted successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return JsonError::serverError($e->getMessage());
        }
    }
    
    /**
     * Pay contact due (settle pending amount for a contact)
     */
    public function payContactDue(Request $request, $contact_id)
    {
        if (! (auth()->user()->can('sell.payments') || auth()->user()->can('purchase.payments'))) {
            return JsonError::forbidden();
        }
        
        try {
            DB::beginTransaction();

            $business_id = pos_context('business.id');
            // Mocking the request payload as transactionUtil->payContact needs a request object
            // This replicates postPayContactDue logic.
            $request->merge(['contact_id' => $contact_id]); 
            $tp = $this->transactionUtil->payContact($request);

            DB::commit();
            
            return new PaymentResource($tp);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return JsonError::serverError($e->getMessage());
        }
    }
}
