<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function store(StoreExpenseRequest $request)
    {
        $expense = Expense::create($request->validated());

        return response()->json([
            'message' => 'Expense created successfully',
            'data' => $expense
        ], 201);
    }

    public function index()
    {
        $expenses = Expense::all();
        return response()->json($expenses);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());

        return response()->json([
            'message' => 'Expense updated successfully',
            'data' => $expense
        ]);
    }

    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);
        $expense->delete();

        return response()->json(['message' => 'Expense deleted successfully']);
    }
}