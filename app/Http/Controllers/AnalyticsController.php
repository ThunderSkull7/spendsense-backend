<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Income;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Get monthly cash flow summary (income, expenses, net).
     * Query params: year, month (optional - defaults to current)
     */
    public function monthlyCashFlow(Request $request): JsonResponse
    {
        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        // Validate month/year
        if ($month < 1 || $month > 12) {
            return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
        }

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->clone()->endOfMonth();

        $userId = auth()->id();

        $totalIncome = Income::whereUser($userId)
            ->whereBetween('income_date', [$startDate, $endDate])
            ->sum('amount');

        $totalExpenses = Expense::whereUser($userId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        $netCashFlow = $totalIncome - $totalExpenses;

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'total_income' => (float)$totalIncome,
                'total_expenses' => (float)$totalExpenses,
                'net_cash_flow' => (float)$netCashFlow,
            ],
        ]);
    }

    /**
     * Get spending breakdown by category for a given month.
     * Query params: year, month (optional - defaults to current)
     */
    public function spendingByCategory(Request $request): JsonResponse
    {
        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        if ($month < 1 || $month > 12) {
            return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
        }

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->clone()->endOfMonth();

        $userId = auth()->id();

        $breakdown = Expense::whereUser($userId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(function ($expenses) {
                $total = $expenses->sum('amount');
                return [
                    'category_id' => $expenses[0]->category_id,
                    'category_name' => $expenses[0]->category->name,
                    'total_spent' => (float)$total,
                    'count' => count($expenses),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'breakdown' => $breakdown,
            ],
        ]);
    }

    /**
     * Compare current month vs previous month (income, expenses, net).
     * Query params: year, month (optional - defaults to current)
     */
    public function monthComparison(Request $request): JsonResponse
    {
        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        if ($month < 1 || $month > 12) {
            return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
        }

        $userId = auth()->id();

        // Current month
        $currentStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $currentEnd = $currentStart->clone()->endOfMonth();

        $currentIncome = Income::whereUser($userId)
            ->whereBetween('income_date', [$currentStart, $currentEnd])
            ->sum('amount');

        $currentExpenses = Expense::whereUser($userId)
            ->whereBetween('expense_date', [$currentStart, $currentEnd])
            ->sum('amount');

        // Previous month
        $previousStart = $currentStart->clone()->subMonth()->startOfMonth();
        $previousEnd = $previousStart->clone()->endOfMonth();

        $previousIncome = Income::whereUser($userId)
            ->whereBetween('income_date', [$previousStart, $previousEnd])
            ->sum('amount');

        $previousExpenses = Expense::whereUser($userId)
            ->whereBetween('expense_date', [$previousStart, $previousEnd])
            ->sum('amount');

        // Calculate changes
        $incomeChange = $previousIncome > 0 ? (($currentIncome - $previousIncome) / $previousIncome) * 100 : 0;
        $expenseChange = $previousExpenses > 0 ? (($currentExpenses - $previousExpenses) / $previousExpenses) * 100 : 0;

        // Format previous month number
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;

        return response()->json([
            'success' => true,
            'data' => [
                'current_month' => [
                    'month' => $month,
                    'year' => $year,
                    'income' => (float)$currentIncome,
                    'expenses' => (float)$currentExpenses,
                    'net' => (float)($currentIncome - $currentExpenses),
                ],
                'previous_month' => [
                    'month' => $prevMonth,
                    'year' => $prevYear,
                    'income' => (float)$previousIncome,
                    'expenses' => (float)$previousExpenses,
                    'net' => (float)($previousIncome - $previousExpenses),
                ],
                'change_percentage' => [
                    'income' => (float)$incomeChange,
                    'expenses' => (float)$expenseChange,
                ],
            ],
        ]);
    }

    /**
     * Get budget status for all categories in a given month.
     * Query params: year, month (optional - defaults to current)
     * Returns: budget limit, spent amount, remaining, percentage used, overspent flag
     */
    public function budgetStatus(Request $request): JsonResponse
    {
        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        if ($month < 1 || $month > 12) {
            return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
        }

        $userId = auth()->id();
        $budgetDate = Carbon::createFromDate($year, $month, 1);

        // Get all budgets for this month
        $budgets = Budget::whereUser($userId)
            ->with('category')
            ->whereYear('month_year', $year)
            ->whereMonth('month_year', $month)
            ->get();

        if ($budgets->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'budgets' => [],
                ],
            ]);
        }

        $startDate = $budgetDate->clone()->startOfMonth();
        $endDate = $budgetDate->clone()->endOfMonth();

        $budgetStatus = $budgets->map(function ($budget) use ($userId, $startDate, $endDate) {
            $spent = Expense::whereUser($userId)
                ->where('category_id', $budget->category_id)
                ->whereBetween('expense_date', [$startDate, $endDate])
                ->sum('amount');

            $limit = $budget->limit_amount;
            $remaining = $limit - $spent;
            $percentageUsed = $limit > 0 ? ($spent / $limit) * 100 : 0;
            $isOverspent = $spent > $limit;

            return [
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
                'category_name' => $budget->category->name,
                'limit_amount' => (float)$limit,
                'spent_amount' => (float)$spent,
                'remaining_amount' => (float)$remaining,
                'percentage_used' => (float)$percentageUsed,
                'is_overspent' => $isOverspent,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'budgets' => $budgetStatus,
            ],
        ]);
    }

    /**
     * Get budget alerts for the authenticated user.
     * Returns budgets that are near limit (>= 80%) or overspent.
     * Query params: year, month (optional - defaults to current)
     */
    public function budgetAlerts(Request $request): JsonResponse
    {
        $year = $request->query('year', Carbon::now()->year);
        $month = $request->query('month', Carbon::now()->month);

        if ($month < 1 || $month > 12) {
            return response()->json(['success' => false, 'message' => 'Invalid month'], 422);
        }

        $userId = auth()->id();
        $budgetDate = Carbon::createFromDate($year, $month, 1);

        $budgets = Budget::whereUser($userId)
            ->with('category')
            ->whereYear('month_year', $year)
            ->whereMonth('month_year', $month)
            ->get();

        $startDate = $budgetDate->clone()->startOfMonth();
        $endDate = $budgetDate->clone()->endOfMonth();

        $alerts = $budgets
            ->map(function ($budget) use ($userId, $startDate, $endDate) {
                $spent = Expense::whereUser($userId)
                    ->where('category_id', $budget->category_id)
                    ->whereBetween('expense_date', [$startDate, $endDate])
                    ->sum('amount');

                $limit = $budget->limit_amount;
                $percentageUsed = $limit > 0 ? ($spent / $limit) * 100 : 0;
                $isOverspent = $spent > $limit;

                // Alert if overspent or >= 80% used
                if ($isOverspent || $percentageUsed >= 80) {
                    return [
                        'budget_id' => $budget->id,
                        'category_id' => $budget->category_id,
                        'category_name' => $budget->category->name,
                        'limit_amount' => (float)$limit,
                        'spent_amount' => (float)$spent,
                        'percentage_used' => (float)$percentageUsed,
                        'alert_type' => $isOverspent ? 'overspent' : 'near_limit',
                        'message' => $isOverspent
                            ? "Budget exceeded by " . round($spent - $limit, 2)
                            : "Budget usage at " . round($percentageUsed, 1) . "%",
                    ];
                }
                return null;
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'alert_count' => count($alerts),
                'alerts' => $alerts,
            ],
        ]);
    }
}
