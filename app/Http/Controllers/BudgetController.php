<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use App\Models\Category;
use App\Services\BudgetAlertService;
use Illuminate\Database\QueryException;
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
        $validated = $request->validated();

        $category = Category::find($validated['category_id']);
        if ($category === null || $category->type !== 'expense') {
            return response()->json([
                'success' => false,
                'message' => 'Budget category must be of type expense.',
            ], 422);
        }

        try {
            $budget = Budget::create([
                'user_id'      => auth()->id(),
                'category_id'  => $validated['category_id'],
                'limit_amount' => $validated['limit_amount'],
                'month_year'   => $validated['month_year'],
                'alert_thresholds' => $this->sortedThresholds(
                    $validated['alert_thresholds'] ?? BudgetAlertService::DEFAULT_THRESHOLDS
                ),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A budget already exists for this category and month.',
                ], 422);
            }

            throw $exception;
        }

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

        $validated = $request->validated();

        if (array_key_exists('alert_thresholds', $validated)) {
            $validated['alert_thresholds'] = $this->sortedThresholds($validated['alert_thresholds']);
        }

        if (array_key_exists('category_id', $validated)) {
            $category = Category::find($validated['category_id']);
            if ($category === null || $category->type !== 'expense') {
                return response()->json([
                    'success' => false,
                    'message' => 'Budget category must be of type expense.',
                ], 422);
            }
        }

        try {
            $budget->update($validated);

            return response()->json([
                'success' => true,
                'data'    => $budget->fresh()->load('category'),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A budget already exists for this category and month.',
                ], 422);
            }

            throw $exception;
        }
    }

    public function destroy(Budget $budget): JsonResponse
    {
        if ($budget->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $budget->delete();

        return response()->json(null, 204);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    private function sortedThresholds(array $thresholds): array
    {
        $values = array_values(array_unique(array_map('intval', $thresholds)));
        sort($values);

        return $values;
    }
}
