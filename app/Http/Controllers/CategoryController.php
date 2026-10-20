<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::orderBy('type')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $category,
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $category,
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $validated = $request->validated();

        // Changing the type would make existing expenses or budgets point at
        // a category of the wrong kind. Require a new category instead.
        if (array_key_exists('type', $validated) && $validated['type'] !== $category->type) {
            if ($category->expenses()->withTrashed()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change category type: category has associated expenses.',
                ], 422);
            }

            if ($category->recurringExpenses()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change category type: category has associated recurring expense schedules.',
                ], 422);
            }

            if ($category->budgets()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change category type: category has associated budgets.',
                ], 422);
            }

            if ($category->subscriptions()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change category type: category has associated subscription trackers.',
                ], 422);
            }
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $category->fresh(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        // Prevent deletion if category is referenced by any expense, including
        // soft-deleted expenses whose row still owns the foreign key.
        if ($category->expenses()->withTrashed()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated expenses.',
            ], 422);
        }

        // Prevent deletion if a recurring expense schedule still references it.
        if ($category->recurringExpenses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated recurring expense schedules.',
            ], 422);
        }

        // Prevent deletion if category is referenced by any budget
        if ($category->budgets()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated budgets.',
            ], 422);
        }

        if ($category->subscriptions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated subscription trackers.',
            ], 422);
        }

        try {
            $category->delete();
        } catch (QueryException $exception) {
            // A concurrent insert can win the race after the checks above.
            // The database foreign key remains the final authority.
            if (in_array((string) $exception->getCode(), ['23000', '23503', '1451'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category: it is still referenced by financial records.',
                ], 422);
            }

            throw $exception;
        }

        return response()->json(null, 204);
    }
}