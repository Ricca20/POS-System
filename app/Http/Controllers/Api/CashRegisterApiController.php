<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Responses\JsonError;
use App\CashRegister;
use App\Utils\CashRegisterUtil;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\CashRegisterResource;

class CashRegisterApiController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $cashRegisterUtil;

    /**
     * Constructor
     *
     * @param CashRegisterUtil $cashRegisterUtil
     * @return void
     */
    public function __construct(CashRegisterUtil $cashRegisterUtil)
    {
        $this->cashRegisterUtil = $cashRegisterUtil;
    }

    /**
     * Get the current open register for the user.
     *
     * @return \Illuminate\Http\Response
     */
    public function current()
    {
        try {
            $user_id = pos_context('user.id');
            
            $register = CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->with('location')
                                ->first();
                                
            if (!$register) {
                return response()->json([
                    'success' => true,
                    'data' => null
                ]);
            }
            
            // Add computed totals if needed
            $register->totals_by_payment_method = $this->cashRegisterUtil->getRegisterDetails($register->id);
            
            return response()->json([
                'success' => true,
                'data' => new CashRegisterResource($register)
            ]);
            
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to fetch current register.');
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function open(Request $request)
    {
        try {
            $user_id = pos_context('user.id');
            $business_id = pos_context('business.id');

            //Check if register is already open
            $count = CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->count();
            if ($count > 0) {
                return JsonError::badRequest('Register already open');
            }

            $register_data = [
                'business_id' => $business_id,
                'user_id' => $user_id,
                'status' => 'open',
                'location_id' => $request->input('location_id'),
                'created_at' => \Carbon::now()->format('Y-m-d H:i:00'),
                'initial_amount' => $this->cashRegisterUtil->num_uf($request->input('initial_amount', 0))
            ];

            $register = CashRegister::create($register_data);

            return response()->json([
                'success' => true,
                'msg' => __('cash_register.cash_register_opened_success'),
                'data' => new CashRegisterResource($register)
            ], 201);

        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to open cash register.');
        }
    }

    /**
     * Close the register.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function close(Request $request)
    {
        if (! auth()->user()->can('close_cash_register')) {
            return JsonError::unauthorized();
        }

        try {
            $user_id = pos_context('user.id');
            
            $register = CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->first();

            if (!$register) {
                return JsonError::badRequest('No open register found');
            }

            $register->closing_amount = $this->cashRegisterUtil->num_uf($request->input('closing_amount', 0));
            $register->total_card_slips = $request->input('total_card_slips', 0);
            $register->total_cheques = $request->input('total_cheques', 0);
            $register->closing_note = $request->input('closing_note');
            $register->closed_at = \Carbon::now()->format('Y-m-d H:i:s');
            $register->status = 'close';

            $register->save();

            return response()->json([
                'success' => true,
                'msg' => __('cash_register.cash_register_closed_success'),
                'data' => new CashRegisterResource($register)
            ]);

        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to close cash register.');
        }
    }

    /**
     * Get details of the register.
     *
     * @return \Illuminate\Http\Response
     */
    public function details()
    {
        if (! auth()->user()->can('view_cash_register')) {
            return JsonError::unauthorized();
        }

        try {
            $user_id = pos_context('user.id');
            
            $register = CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->first();

            if (!$register) {
                return JsonError::badRequest('No open register found');
            }
            
            $register_details = $this->cashRegisterUtil->getRegisterDetails($register->id);
            $register->totals_by_payment_method = $register_details;

            return response()->json([
                'success' => true,
                'data' => new CashRegisterResource($register)
            ]);
            
        } catch (\Exception $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());
            return JsonError::internalServerError('Failed to get register details.');
        }
    }
}
