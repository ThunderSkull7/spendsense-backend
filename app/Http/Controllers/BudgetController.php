<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use Illuminate\Http\JsonResponse;

class BudgetController extends Controller
{
    public function index(): JsonResponse
    {
        $budgets = Budget::with('category')
            ->whereUser(auth()->id())
            ->orderByDesc('month_year')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $budgets,
        ]);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = Budget::create([
            'user_id'      => auth()->id(),
            'category_id'  => $request->validated('category_id'),
            'limit_amount' => $request->validated('limit_amount'),
            'month_year'   => $request->validated('month_year'),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $budget->load('category'),
        ], 201);
    }

    public function show(Budget $budget): JsonResponse
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $budget->load('category'),
        ]);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $budget->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $budget->fresh()->load('category'),
        ]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $budget->delete();

        return response()->json(null, 204);
    }
}
