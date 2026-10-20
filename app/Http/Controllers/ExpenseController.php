<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Category;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;

class ExpenseController extends Controller
{
    public function index(): JsonResponse
    {
        $expenses = Expense::with('category')
            ->whereUser(auth()->id())
            ->orderByDesc('expense_date')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $expenses,
        ]);
    }

    public function trash(): JsonResponse
    {
        $expenses = Expense::onlyTrashed()
            ->with('category')
            ->whereUser(auth()->id())
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $expenses,
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = Category::findOrFail($validated['category_id']);
        if ($category->type !== 'expense') {
            return response()->json([
                'success' => false,
                'message' => 'Category must be of type expense.',
            ], 422);
        }

        $expense = Expense::create([
            'user_id'      => auth()->id(),
            'category_id'  => $validated['category_id'],
            'amount'       => $validated['amount'],
            'description'  => $validated['description'] ?? null,
            'expense_date' => $validated['expense_date'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $expense->load('category'),
        ], 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        if ($expense->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $expense->load('category'),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        if ($expense->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = $request->validated();

        if (isset($validated['category_id'])) {
            $category = Category::findOrFail($validated['category_id']);
            if ($category->type !== 'expense') {
                return response()->json([
                    'success' => false,
                    'message' => 'Category must be of type expense.',
                ], 422);
            }
        }

        $expense->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $expense->fresh()->load('category'),
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        if ($expense->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $expense->delete();

        return response()->json(null, 204);
    }

    public function restore(int $expense): JsonResponse
    {
        $expense = Expense::onlyTrashed()
            ->whereUser(auth()->id())
            ->findOrFail($expense);

        $expense->restore();

        return response()->json([
            'success' => true,
            'data'    => $expense->fresh()->load('category'),
        ]);
    }
}
