<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectSavingsGoalContributionRequest;
use App\Http\Requests\StoreSavingsGoalContributionRequest;
use App\Http\Requests\StoreSavingsGoalRequest;
use App\Http\Requests\UpdateSavingsGoalRequest;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
use App\Services\SavingsGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsGoalController extends Controller
{
    public function __construct(private readonly SavingsGoalService $goals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,completed,archived',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->goals->payloadForUser(
                auth()->id(),
                $validated['status'] ?? null
            ),
        ]);
    }

    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->goals->create(auth()->id(), $request->validated()),
        ], 201);
    }

    public function show(SavingsGoal $savingsGoal): JsonResponse
    {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        $savingsGoal->load(['contributions' => fn ($query) => $query
            ->orderByDesc('contributed_at')
            ->orderByDesc('id')]);

        return response()->json([
            'success' => true,
            'data'    => $this->goals->goalPayload($savingsGoal, true),
        ]);
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal): JsonResponse
    {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->goals->update($savingsGoal, $request->validated()),
        ]);
    }

    public function archive(SavingsGoal $savingsGoal): JsonResponse
    {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->goals->archive($savingsGoal),
        ]);
    }

    public function restore(SavingsGoal $savingsGoal): JsonResponse
    {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->goals->restore($savingsGoal),
        ]);
    }

    public function storeContribution(
        StoreSavingsGoalContributionRequest $request,
        SavingsGoal $savingsGoal
    ): JsonResponse {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->goals->addEntry(
                $savingsGoal,
                $request->validated(),
                SavingsGoalContribution::TYPE_CONTRIBUTION
            ),
        ], 201);
    }

    public function correctContribution(
        CorrectSavingsGoalContributionRequest $request,
        SavingsGoal $savingsGoal
    ): JsonResponse {
        if ($savingsGoal->user_id !== auth()->id()) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data'    => $this->goals->addEntry(
                $savingsGoal,
                $request->validated(),
                SavingsGoalContribution::TYPE_CORRECTION
            ),
        ], 201);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
    }
}
