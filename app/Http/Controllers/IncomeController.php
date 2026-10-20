<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIncomeRequest;
use App\Http\Requests\UpdateIncomeRequest;
use App\Models\Income;
use Illuminate\Http\JsonResponse;

class IncomeController extends Controller
{
    public function index(): JsonResponse
    {
        $incomes = Income::query()
            ->whereUser(auth()->id())
            ->orderByDesc('income_date')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $incomes,
        ]);
    }

    public function trash(): JsonResponse
    {
        $incomes = Income::onlyTrashed()
            ->whereUser(auth()->id())
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $incomes,
        ]);
    }

    public function store(StoreIncomeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $income = Income::create([
            'user_id'    => auth()->id(),
            'amount'     => $validated['amount'],
            'source'     => $validated['source'],
            'income_date' => $validated['income_date'],
            'frequency'  => $validated['frequency'] ?? null,
            'notes'      => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $income,
        ], 201);
    }

    public function show(Income $income): JsonResponse
    {
        if ($income->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $income,
        ]);
    }

    public function update(UpdateIncomeRequest $request, Income $income): JsonResponse
    {
        if ($income->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $income->update($request->validated());

        return response()->json([
            'success' => true,
            'data'    => $income->fresh(),
        ]);
    }

    public function destroy(Income $income): JsonResponse
    {
        if ($income->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        // An income recorded from a recurring schedule is the receipt history
        // for that occurrence. Deleting it would leave a received occurrence
        // with no actual record, so the link must be removed through the
        // recurring-income workflow instead.
        if ($income->recurringOccurrence()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This income is linked to a recurring income receipt and cannot be deleted.',
            ], 422);
        }

        $income->delete();

        return response()->json(null, 204);
    }

    public function restore(int $income): JsonResponse
    {
        $income = Income::onlyTrashed()
            ->whereUser(auth()->id())
            ->findOrFail($income);

        $income->restore();

        return response()->json([
            'success' => true,
            'data'    => $income->fresh(),
        ]);
    }
}
