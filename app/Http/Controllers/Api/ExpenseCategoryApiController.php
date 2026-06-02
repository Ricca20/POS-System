<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\ExpenseCategory;

class ExpenseCategoryApiController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('expense.access')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $business_id = pos_context('business.id');

        $categories = ExpenseCategory::where('business_id', $business_id)
                        ->whereNull('parent_id')
                        ->with(['sub_categories'])
                        ->get();

        return response()->json([
            'data' => $categories
        ]);
    }
}
