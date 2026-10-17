<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
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
        $category->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $category->fresh(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        // Prevent deletion if category is referenced by any expense
        if ($category->expenses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated expenses.',
            ], 422);
        }

        // Prevent deletion if category is referenced by any budget
        if ($category->budgets()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category: category has associated budgets.',
            ], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}