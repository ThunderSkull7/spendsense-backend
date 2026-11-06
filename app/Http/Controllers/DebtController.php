<?php

namespace App\Http\Controllers;

use App\Services\DebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebtController extends Controller
{
    public function __construct(private readonly DebtService $debts) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,completed,archived,all',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->debts->payloadForUser(
                (int) auth()->id(),
                $validated['status'] ?? null
            ),
        ]);
    }

    public function show(int $debt): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->debts->show((int) auth()->id(), $debt),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:120',
            'category'        => 'nullable|string|in:loan,other_liability',
            'lender'          => 'nullable|string|max:120',
            'institution'     => 'nullable|string|max:120',
            'original_amount' => 'required|numeric|min:0.01',
            'current_balance' => 'nullable|numeric|min:0',
            'interest_rate'   => 'nullable|numeric|min:0|max:100',
            'minimum_payment' => 'nullable|numeric|min:0',
            'start_date'      => 'nullable|date_format:Y-m-d',
            'notes'           => 'nullable|string|max:1000',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->debts->create((int) auth()->id(), $validated),
        ], 201);
    }

    public function update(Request $request, int $debt): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'nullable|string|max:120',
            'lender'          => 'nullable|string|max:120',
            'institution'     => 'nullable|string|max:120',
            'interest_rate'   => 'nullable|numeric|min:0|max:100',
            'minimum_payment' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:1000',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->debts->update((int) auth()->id(), $debt, $validated),
        ]);
    }

    public function storeRepayment(Request $request, int $debt): JsonResponse
    {
        $validated = $request->validate([
            'amount'       => 'required|numeric|min:0.01',
            'payment_date' => 'nullable|date_format:Y-m-d',
            'note'         => 'nullable|string|max:500',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->debts->recordRepayment((int) auth()->id(), $debt, $validated),
        ], 201);
    }

    public function archive(int $debt): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->debts->archive((int) auth()->id(), $debt),
        ]);
    }

    public function restore(int $debt): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->debts->restore((int) auth()->id(), $debt),
        ]);
    }
}

