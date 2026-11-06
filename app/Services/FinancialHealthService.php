<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\SavingsGoal;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialHealthService
{
    /**
     * Compute evidence-based financial health indicators for a given user and calendar month.
     * All calculations are scoped to the authenticated user and avoid division-by-zero errors.
     *
     * @return array<string, mixed>
     */
    public function forUser(int $userId, ?string $monthStr = null, ?int $month = null, ?int $year = null): array
    {
        if ($monthStr !== null && preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
        }

        $today = FinancialCalendar::today();
        $targetYear = $year ?? $today->year;
        $targetMonth = $month ?? $today->month;

        $currentStart = CarbonImmutable::create($targetYear, $targetMonth, 1)->startOfMonth();
        $currentEnd = $currentStart->endOfMonth();
        $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        $fromCurrent = $currentStart->toDateString();
        $toCurrent = $currentEnd->toDateString();
        $fromPrevious = $previousStart->toDateString();
        $toPrevious = $previousEnd->toDateString();

        // 1. Incomes & Expenses (in cents)
        $currentIncomeCents = (int) round((float) Income::query()
            ->whereUser($userId)
            ->whereDate('income_date', '>=', $fromCurrent)
            ->whereDate('income_date', '<=', $toCurrent)
            ->sum('amount') * 100);

        $currentExpenseCents = (int) round((float) Expense::query()
            ->whereUser($userId)
            ->whereDate('expense_date', '>=', $fromCurrent)
            ->whereDate('expense_date', '<=', $toCurrent)
            ->sum('amount') * 100);

        $previousExpenseCents = (int) round((float) Expense::query()
            ->whereUser($userId)
            ->whereDate('expense_date', '>=', $fromPrevious)
            ->whereDate('expense_date', '<=', $toPrevious)
            ->sum('amount') * 100);

        // Net Cash Flow
        $netCashFlowCents = $currentIncomeCents - $currentExpenseCents;

        // Savings Rate: (income - expenses) / income * 100
        $savingsRate = null;
        $savingsRateStatus = 'not_available';
        if ($currentIncomeCents > 0) {
            $savingsRate = round(($netCashFlowCents / $currentIncomeCents) * 100, 1);
            $savingsRateStatus = $savingsRate >= 0 ? 'positive' : 'negative';
        }

        // 2. Spending Change vs Prior Month
        $spendingChangePercent = null;
        $spendingChangeDirection = 'no_prior_data';
        if ($previousExpenseCents > 0) {
            $deltaExpense = $currentExpenseCents - $previousExpenseCents;
            $spendingChangePercent = round(($deltaExpense / $previousExpenseCents) * 100, 1);
            $spendingChangeDirection = $deltaExpense > 0 ? 'higher' : ($deltaExpense < 0 ? 'lower' : 'same');
        } elseif ($currentExpenseCents > 0 && $previousExpenseCents === 0) {
            $spendingChangeDirection = 'new_spending';
        }

        // 3. Budget Utilization for this month
        $budgets = Budget::query()
            ->whereUser($userId)
            ->whereBetween('month_year', [$fromCurrent, $toCurrent])
            ->get();

        $budgetCount = $budgets->count();
        $totalBudgetLimitCents = (int) round((float) $budgets->sum('limit_amount') * 100);
        $totalBudgetSpentCents = 0;
        $overBudgetCount = 0;

        if ($budgetCount > 0) {
            $categoryIds = $budgets->pluck('category_id')->unique()->all();
            $categorySpends = Expense::query()
                ->whereUser($userId)
                ->whereDate('expense_date', '>=', $fromCurrent)
                ->whereDate('expense_date', '<=', $toCurrent)
                ->whereIn('category_id', $categoryIds)
                ->selectRaw('category_id, SUM(amount) as total')
                ->groupBy('category_id')
                ->pluck('total', 'category_id')
                ->all();

            foreach ($budgets as $b) {
                $spentForCategory = (int) round((float) ($categorySpends[$b->category_id] ?? 0) * 100);
                $totalBudgetSpentCents += $spentForCategory;
                $limitForCategory = (int) round((float) $b->limit_amount * 100);
                if ($limitForCategory > 0 && $spentForCategory > $limitForCategory) {
                    $overBudgetCount++;
                }
            }
        }

        $budgetUtilizationPercent = $totalBudgetLimitCents > 0
            ? round(($totalBudgetSpentCents / $totalBudgetLimitCents) * 100, 1)
            : null;

        // 4. Recurring Commitments
        $recurringSchedules = RecurringExpense::query()
            ->whereUser($userId)
            ->where('status', 'active')
            ->get();

        $monthlyCommitmentsCents = 0;
        foreach ($recurringSchedules as $sched) {
            $amtCents = Money::toCents((string) $sched->amount);
            $monthlyCents = match ($sched->frequency) {
                'daily' => (int) round(($amtCents * 365) / 12),
                'weekly' => (int) round(($amtCents * 52) / 12),
                'biweekly' => (int) round(($amtCents * 26) / 12),
                'monthly' => $amtCents,
                'quarterly' => (int) round($amtCents / 3),
                'half-yearly' => (int) round($amtCents / 6),
                'yearly' => (int) round($amtCents / 12),
                default => $amtCents,
            };
            $monthlyCommitmentsCents += $monthlyCents;
        }

        $commitmentsRatioPercent = $currentIncomeCents > 0
            ? round(($monthlyCommitmentsCents / $currentIncomeCents) * 100, 1)
            : null;

        // 5. Goals progress
        $activeGoals = SavingsGoal::query()
            ->whereUser($userId)
            ->where('status', SavingsGoal::STATUS_ACTIVE)
            ->get();

        $totalGoalTargetCents = 0;
        $totalGoalSavedCents = 0;
        $overdueGoalsCount = 0;

        foreach ($activeGoals as $goal) {
            $targetC = Money::toCents((string) $goal->target_amount);
            $totalGoalTargetCents += $targetC;

            $totals = DB::table('savings_goal_contributions')
                ->where('savings_goal_id', $goal->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'contribution' THEN amount ELSE 0 END), 0) as contributed")
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'correction' THEN amount ELSE 0 END), 0) as corrected")
                ->first();
            $savedC = Money::toCents((string) $totals->contributed) - Money::toCents((string) $totals->corrected);
            $totalGoalSavedCents += max(0, $savedC);

            if ($goal->target_date !== null && $goal->target_date->lt($today) && $savedC < $targetC) {
                $overdueGoalsCount++;
            }
        }

        $goalsOverallProgressPercent = $totalGoalTargetCents > 0
            ? round(($totalGoalSavedCents / $totalGoalTargetCents) * 100, 1)
            : null;

        // 6. Actionable Observations ("Needs Attention")
        $observations = [];

        if ($currentIncomeCents === 0 && $currentExpenseCents === 0) {
            $observations[] = [
                'type' => 'info',
                'title' => 'No activity recorded this month',
                'description' => 'Add your incomes and expenses to see your cash flow and savings rate.',
                'link' => '../expense-management/expenses.html',
                'link_text' => 'Add transactions',
            ];
        } elseif ($netCashFlowCents < 0) {
            $observations[] = [
                'type' => 'warning',
                'title' => 'Expenses exceed income',
                'description' => 'You spent ' . Money::fromCents(abs($netCashFlowCents)) . ' more than your recorded income this period.',
                'link' => '../reports-analytics/reports.html',
                'link_text' => 'Review spending',
            ];
        } elseif ($savingsRate !== null && $savingsRate > 20) {
            $observations[] = [
                'type' => 'success',
                'title' => 'Healthy savings rate',
                'description' => "You have saved {$savingsRate}% of your income during this period.",
                'link' => '../budget-planning/budget.html?tab=goals',
                'link_text' => 'View goals',
            ];
        }

        if ($overBudgetCount > 0) {
            $observations[] = [
                'type' => 'danger',
                'title' => "{$overBudgetCount} budget(s) exceeded",
                'description' => 'One or more category budgets have crossed 100% of their monthly limit.',
                'link' => '../budget-planning/budget.html',
                'link_text' => 'Adjust budgets',
            ];
        }

        if ($spendingChangeDirection === 'higher' && $spendingChangePercent !== null && $spendingChangePercent >= 15.0) {
            $observations[] = [
                'type' => 'warning',
                'title' => 'Higher spending than last month',
                'description' => "Total expenses are {$spendingChangePercent}% higher than the previous month.",
                'link' => '../reports-analytics/reports.html',
                'link_text' => 'Compare months',
            ];
        }

        if ($overdueGoalsCount > 0) {
            $observations[] = [
                'type' => 'warning',
                'title' => "{$overdueGoalsCount} savings goal(s) past target date",
                'description' => 'Review your goal targets or adjust target dates.',
                'link' => '../budget-planning/budget.html?tab=goals',
                'link_text' => 'Manage goals',
            ];
        }

        return [
            'period' => [
                'year' => $targetYear,
                'month' => $targetMonth,
                'month_key' => $currentStart->format('Y-m'),
                'label' => $currentStart->format('F Y'),
                'from' => $fromCurrent,
                'to' => $toCurrent,
            ],
            'indicators' => [
                'net_cash_flow' => [
                    'income' => Money::fromCents($currentIncomeCents),
                    'expenses' => Money::fromCents($currentExpenseCents),
                    'net' => Money::fromCents($netCashFlowCents),
                    'status' => $netCashFlowCents > 0 ? 'positive' : ($netCashFlowCents < 0 ? 'negative' : 'neutral'),
                ],
                'savings_rate' => [
                    'rate_percent' => $savingsRate,
                    'status' => $savingsRateStatus,
                    'label' => $savingsRate !== null ? "{$savingsRate}%" : 'Not available',
                ],
                'budget_utilization' => [
                    'total_limit' => Money::fromCents($totalBudgetLimitCents),
                    'total_spent' => Money::fromCents($totalBudgetSpentCents),
                    'utilization_percent' => $budgetUtilizationPercent,
                    'budget_count' => $budgetCount,
                    'over_budget_count' => $overBudgetCount,
                    'status' => $budgetUtilizationPercent === null ? 'no_budgets' : ($budgetUtilizationPercent > 100 ? 'exceeded' : ($budgetUtilizationPercent >= 80 ? 'warning' : 'on_track')),
                ],
                'spending_change' => [
                    'current_spend' => Money::fromCents($currentExpenseCents),
                    'prior_spend' => Money::fromCents($previousExpenseCents),
                    'change_percent' => $spendingChangePercent,
                    'direction' => $spendingChangeDirection,
                    'label' => $spendingChangePercent !== null ? ($spendingChangePercent >= 0 ? "+{$spendingChangePercent}%" : "{$spendingChangePercent}%") : 'No prior data',
                ],
                'recurring_commitments' => [
                    'monthly_cost' => Money::fromCents($monthlyCommitmentsCents),
                    'ratio_to_income_percent' => $commitmentsRatioPercent,
                    'active_schedules_count' => $recurringSchedules->count(),
                ],
                'goals_progress' => [
                    'active_goals_count' => $activeGoals->count(),
                    'total_target' => Money::fromCents($totalGoalTargetCents),
                    'total_saved' => Money::fromCents($totalGoalSavedCents),
                    'overall_progress_percent' => $goalsOverallProgressPercent,
                ],
            ],
            'observations' => $observations,
            'definitions' => [
                'net_cash_flow' => 'Total recorded income minus total recorded expenses for the calendar month.',
                'savings_rate' => 'Net cash flow expressed as a percentage of income. Not available when income is zero.',
                'budget_utilization' => 'Total spending in budgeted categories divided by total budget limits for the month.',
                'spending_change' => 'Percentage difference in expenses compared to the previous calendar month.',
                'recurring_commitments' => 'Normalized monthly cost of active recurring bills divided by monthly income.',
                'goals_progress' => 'Total amount saved across active savings goals divided by total target amounts.',
            ],
        ];
    }
}

