<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommitmentInsightsRequest;
use App\Http\Requests\InsightRangeRequest;
use App\Http\Requests\SpendingTrendsRequest;
use App\Services\BudgetPerformanceService;
use App\Services\CommitmentService;
use App\Services\DataQualityService;
use App\Services\IncomeAnalysisService;
use App\Services\SavingsProgressService;
use App\Services\SpendingTrendService;
use Illuminate\Http\JsonResponse;

class AnalyticsInsightsController extends Controller
{
    public function __construct(
        private readonly SpendingTrendService $trends,
        private readonly IncomeAnalysisService $income,
        private readonly BudgetPerformanceService $budgets,
        private readonly SavingsProgressService $savings,
        private readonly CommitmentService $commitments,
        private readonly DataQualityService $quality
    ) {}

    public function spendingTrends(SpendingTrendsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->trends->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['granularity'] ?? 'day'
        ));
    }

    public function incomeAnalysis(InsightRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->income->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null
        ));
    }

    public function budgetPerformance(InsightRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->budgets->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null
        ));
    }

    public function savingsProgress(InsightRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->savings->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null
        ));
    }

    public function commitments(CommitmentInsightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->commitments->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['as_of'] ?? null
        ));
    }

    public function dataQuality(InsightRangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->quality->forUser(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
