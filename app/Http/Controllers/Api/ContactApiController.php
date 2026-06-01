<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Responses\JsonError;
use App\TransactionPayment;
use App\Utils\ContactUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactApiController extends Controller
{
    protected $contactUtil;

    public function __construct(ContactUtil $contactUtil)
    {
        $this->contactUtil = $contactUtil;
    }

    /**
     * Display a listing of contacts (paginated).
     */
    public function index(Request $request)
    {
        $type = $request->query('type');
        if (!in_array($type, ['supplier', 'customer', 'both'])) {
            return JsonError::validationFailed(['type' => 'The type parameter is required and must be supplier, customer, or both.']);
        }

        // Permission check
        if ($type === 'supplier' && !auth()->user()->can('supplier.view') && !auth()->user()->can('supplier.view_own')) {
            return JsonError::forbidden();
        }
        if ($type === 'customer' && !auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            return JsonError::forbidden();
        }

        $business_id = pos_context('business.id');

        $query = $this->contactUtil->getContactQuery($business_id, $type);

        if ($request->has('q')) {
            $search = $request->query('q');
            $query->where(function ($q) use ($search) {
                $q->where('contacts.name', 'like', "%{$search}%")
                    ->orWhere('contacts.supplier_business_name', 'like', "%{$search}%")
                    ->orWhere('contacts.email', 'like', "%{$search}%")
                    ->orWhere('contacts.contact_id', 'like', "%{$search}%")
                    ->orWhere('contacts.mobile', 'like', "%{$search}%");
            });
        }

        if ($request->has('customer_group_id')) {
            $query->where('contacts.customer_group_id', $request->query('customer_group_id'));
        }

        if ($request->has('contact_status')) {
            $query->where('contacts.contact_status', $request->query('contact_status'));
        }

        $contacts = $query->paginate($request->query('per_page', 20));

        return ContactResource::collection($contacts);
    }

    /**
     * Store a newly created contact.
     */
    public function store(StoreContactRequest $request)
    {
        $type = $request->input('type');

        // Permission check
        if (in_array($type, ['supplier', 'both']) && !auth()->user()->can('supplier.create')) {
            return JsonError::forbidden();
        }
        if (in_array($type, ['customer', 'both']) && !auth()->user()->can('customer.create')) {
            return JsonError::forbidden();
        }

        try {
            $business_id = pos_context('business.id');
            $input = $request->validated();
            $input['business_id'] = $business_id;
            $input['created_by'] = auth()->id();

            // Format name if pieces are missing
            $name_array = [];
            if ($request->filled('first_name')) {
                $name_array[] = $request->input('first_name');
            }
            if ($request->filled('last_name')) {
                $name_array[] = $request->input('last_name');
            }
            $input['name'] = trim(implode(' ', $name_array));

            DB::beginTransaction();
            $output = $this->contactUtil->createNewContact($input);
            DB::commit();

            return new ContactResource($output['data']);
        } catch (\Exception $e) {
            DB::rollBack();
            return JsonError::serverError($e->getMessage());
        }
    }

    /**
     * Display the specified contact.
     */
    public function show($id)
    {
        $business_id = pos_context('business.id');
        $contact = $this->contactUtil->getContactInfo($business_id, $id);

        if (!$contact) {
            return JsonError::notFound('Contact not found.');
        }

        // Simplified permission check
        if (in_array($contact->type, ['supplier', 'both']) && !auth()->user()->can('supplier.view') && !auth()->user()->can('supplier.view_own')) {
            return JsonError::forbidden();
        }
        if (in_array($contact->type, ['customer', 'both']) && !auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            return JsonError::forbidden();
        }

        return new ContactResource($contact);
    }

    /**
     * Update the specified contact.
     */
    public function update(StoreContactRequest $request, $id)
    {
        $business_id = pos_context('business.id');
        $contact = Contact::where('business_id', $business_id)->find($id);

        if (!$contact) {
            return JsonError::notFound('Contact not found.');
        }

        $type = $contact->type;
        // Permission check
        if (in_array($type, ['supplier', 'both']) && !auth()->user()->can('supplier.update')) {
            return JsonError::forbidden();
        }
        if (in_array($type, ['customer', 'both']) && !auth()->user()->can('customer.update')) {
            return JsonError::forbidden();
        }

        try {
            $input = $request->validated();
            
            // Format name if pieces are missing
            $name_array = [];
            if ($request->filled('first_name')) {
                $name_array[] = $request->input('first_name');
            }
            if ($request->filled('last_name')) {
                $name_array[] = $request->input('last_name');
            }
            if (!empty($name_array)) {
                $input['name'] = trim(implode(' ', $name_array));
            }

            DB::beginTransaction();
            $output = $this->contactUtil->updateContact($input, $id, $business_id);
            DB::commit();

            return new ContactResource($output['data']);
        } catch (\Exception $e) {
            DB::rollBack();
            return JsonError::serverError($e->getMessage());
        }
    }

    /**
     * Remove the specified contact.
     */
    public function destroy($id)
    {
        $business_id = pos_context('business.id');
        $contact = Contact::where('business_id', $business_id)->find($id);

        if (!$contact) {
            return JsonError::notFound('Contact not found.');
        }

        if ($contact->is_default) {
            return JsonError::make(400, 'cannot_delete_default', 'Cannot delete default contact.');
        }

        $type = $contact->type;
        if (in_array($type, ['supplier', 'both']) && !auth()->user()->can('supplier.delete')) {
            return JsonError::forbidden();
        }
        if (in_array($type, ['customer', 'both']) && !auth()->user()->can('customer.delete')) {
            return JsonError::forbidden();
        }

        // Check if there are transactions associated
        $transactions = $contact->transactions()->count();
        if ($transactions > 0) {
            return JsonError::make(400, 'has_transactions', 'Cannot delete contact with existing transactions.');
        }

        $contact->delete();

        return response()->json(['success' => true, 'message' => 'Contact deleted.']);
    }

    /**
     * Ledger (transactions history).
     */
    public function ledger(Request $request, $id)
    {
        $business_id = pos_context('business.id');
        $contact = Contact::where('business_id', $business_id)->find($id);

        if (!$contact) {
            return JsonError::notFound('Contact not found.');
        }

        // Simple ledger list
        $transactions = $contact->transactions()
            ->orderBy('transaction_date', 'desc')
            ->paginate($request->query('per_page', 20));

        // Use array for simple ledger output (could use a dedicated resource if needed)
        return response()->json($transactions);
    }

    /**
     * Payments for the contact.
     */
    public function payments(Request $request, $id)
    {
        $business_id = pos_context('business.id');
        $contact = Contact::where('business_id', $business_id)->find($id);

        if (!$contact) {
            return JsonError::notFound('Contact not found.');
        }

        if (!auth()->user()->can('sell.payments') && !auth()->user()->can('purchase.payments')) {
            return JsonError::forbidden();
        }

        $payments = TransactionPayment::where('payment_for', $id)
            ->orderBy('paid_on', 'desc')
            ->paginate($request->query('per_page', 20));

        return response()->json($payments); // Returns standard pagination JSON
    }

    /**
     * Lightweight customer dropdown list.
     */
    public function customers(Request $request)
    {
        if (!auth()->user()->can('customer.view') && !auth()->user()->can('customer.view_own')) {
            return JsonError::forbidden();
        }

        $business_id = pos_context('business.id');
        $query = Contact::onlyCustomers()->where('business_id', $business_id);
        
        if ($request->has('q')) {
            $search = $request->query('q');
            $query->where('name', 'like', "%{$search}%");
        }

        $customers = $query->select('id', 'name', 'mobile')->take(100)->get();
        return response()->json(['data' => $customers]);
    }
}
