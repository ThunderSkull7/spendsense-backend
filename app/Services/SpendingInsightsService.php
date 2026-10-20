<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use App\Support\FinancialCalendar;
use App\Support\FrequencyCost;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SpendingInsightsService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(int $userId, ?string $fromMonth = null, ?string $toMonth = null): array
    {
        $endMonth = $toMonth !== null
            ? FinancialCalendar::parseMonth($toMonth, 'to')
            : FinancialCalendar::today()->startOfMonth();
        $startMonth = $fromMonth !== null
            ? FinancialCalendar::parseMonth($fromMonth, 'from')
            : $endMonth->subMonthsNoOverflow(5)->startOfMonth();

        FinancialCalendar::assertMonthRange($startMonth, $endMonth);

        $rangeStart = $startMonth->toDateString();
        $rangeEnd = $endMonth->endOfMonth()->toDateString();
        $queryStart = $rangeStart.' 00:00:00';
        $queryEnd = $rangeEnd.' 23:59:59';
        // The extra preceding month supplies a real month-over-month baseline
        // even when the user selects a single month. It is not included in the
        // selected-period totals.
        $dataStart = $startMonth->subMonthNoOverflow()->startOfMonth()->toDateString().' 00:00:00';
        $allKeys = FinancialCalendar::monthKeys($startMonth->subMonthNoOverflow(), $endMonth);
        $selectedKeys = FinancialCalendar::monthKeys($startMonth, $endMonth);

        $incomeByDay = $this->dailyTotals('incomes', 'income_date', $userId, $dataStart, $queryEnd);
        $expenseByDay = $this->dailyTotals('expenses', 'expense_date', $userId, $dataStart, $queryEnd);

        $incomeByMonth = array_fill_keys($allKeys, 0);
        $expenseByMonth = array_fill_keys($allKeys, 0);
        $this->bucketByMonth($incomeByDay, $incomeByMonth);
        $this->bucketByMonth($expenseByDay, $expenseByMonth);

        $incomeCents = 0;
        $expenseCents = 0;
        $monthsWithData = 0;
        foreach ($selectedKeys as $key) {
            $incomeCents += $incomeByMonth[$key] ?? 0;
            $expenseCents += $expenseByMonth[$key] ?? 0;
            if (($incomeByMonth[$key] ?? 0) !== 0 || ($expenseByMonth[$key] ?? 0) !== 0) {
                $monthsWithData++;
            }
        }

        $monthCount = count($selectedKeys);
        $incomeCount = $this->recordCount('incomes', 'income_date', $userId, $queryStart, $queryEnd);
        $expenseCount = $this->recordCount('expenses', 'expense_date', $userId, $queryStart, $queryEnd);

        $currentKey = $endMonth->format('Y-m');
        $previousMonth = $endMonth->subMonthNoOverflow()->startOfMonth();
        $previousKey = $previousMonth->format('Y-m');
        $currentIncome = $incomeByMonth[$currentKey] ?? 0;
        $currentExpenses = $expenseByMonth[$currentKey] ?? 0;
        $previousIncome = $incomeByMonth[$previousKey] ?? 0;
        $previousExpenses = $expenseByMonth[$previousKey] ?? 0;

        $monthly = [];
        $today = FinancialCalendar::today();
        foreach ($selectedKeys as $key) {
            $month = FinancialCalendar::parseMonth($key, 'from');
            $monthIncome = $incomeByMonth[$key] ?? 0;
            $monthExpenses = $expenseByMonth[$key] ?? 0;
            $monthly[] = [
                'month' => $key,
                'label' => $month->format('F Y'),
                'income' => Money::fromCents($monthIncome),
                'expenses' => Money::fromCents($monthExpenses),
                'net' => Money::fromCents($monthIncome - $monthExpenses),
                'is_in_progress' => $key === $today->format('Y-m'),
            ];
        }

        $categories = $this->categories($userId, $queryStart, $queryEnd);
        $topCategory = $categories[0] ?? null;
        $largestTransactions = $this->largestTransactions($userId, $queryStart, $queryEnd);
        $anomalies = $this->anomalies($userId, $queryStart, $queryEnd, $expenseCount, $expenseCents);
        $budgets = $this->budgetUtilization($userId, $startMonth, $endMonth);
        $recurring = $this->recurringSummary($userId);

        $subscriptions = $this->subscriptions->summaryForUser($userId);

        return [
            'period' => [
                'from' => $startMonth->format('Y-m'),
                'to' => $endMonth->format('Y-m'),
                'from_date' => $rangeStart,
                'to_date' => $rangeEnd,
                'month_count' => $monthCount,
                'timezone' => FinancialCalendar::TIMEZONE,
                'month_boundary' => 'Inclusive UTC calendar months (first day 00:00 to last day 23:59:59).',
                'current_month_in_progress' => $endMonth->format('Y-m') === $today->format('Y-m'),
            ],
            'summary' => [
                'total_income' => Money::fromCents($incomeCents),
                'total_expenses' => Money::fromCents($expenseCents),
                'net_cash_flow' => Money::fromCents($incomeCents - $expenseCents),
                'average_monthly_income' => Money::fromCents(intdiv($incomeCents, max(1, $monthCount))),
                'average_monthly_expenses' => Money::fromCents(intdiv($expenseCents, max(1, $monthCount))),
                'months_with_data' => $monthsWithData,
                'income_count' => $incomeCount,
                'expense_count' => $expenseCount,
                'largest_category' => $topCategory,
                'has_data' => $incomeCents !== 0 || $expenseCents !== 0,
            ],
            'months' => $monthly,
            'comparison' => [
                'current_month' => $currentKey,
                'previous_month' => $previousKey,
                'current' => [
                    'month' => $currentKey,
                    'income' => Money::fromCents($currentIncome),
                    'expenses' => Money::fromCents($currentExpenses),
                    'net' => Money::fromCents($currentIncome - $currentExpenses),
                ],
                'previous' => [
                    'month' => $previousKey,
                    'income' => Money::fromCents($previousIncome),
                    'expenses' => Money::fromCents($previousExpenses),
                    'net' => Money::fromCents($previousIncome - $previousExpenses),
                ],
                'expense_change_percent' => $this->changePercent($currentExpenses, $previousExpenses),
                'income_change_percent' => $this->changePercent($currentIncome, $previousIncome),
                'basis' => 'The selected end month is compared with the immediately preceding UTC calendar month. Percent change is null when the preceding value is zero.',
            ],
            'categories' => $categories,
            'largest_transactions' => $largestTransactions,
            'anomalies' => $anomalies,
            'recurring_expenses' => $recurring,
            'subscriptions' => $subscriptions,
            'budget_utilization' => $budgets,
            'notes' => [
                'Actual totals use non-deleted expenses and incomes as of the request; edits and restores are reflected on the next request.',
                'Soft-deleted records are excluded. There is no refund transaction type in the existing ledger, so refunds are not automatically netted; correct or delete the original expense instead.',
                'Recurring schedules and subscriptions are expectations for planning and are not added to actual spending.',
                'Savings goal contributions are internal tracking entries and never appear as income or expenses here.',
                'Anomaly screening is descriptive only, not financial advice.',
            ],
        ];
    }

    private function dailyTotals(string $table, string $dateColumn, int $userId, string $start, string $end)
    {
        return DB::table($table)
            ->select($dateColumn.' as entry_date')
            ->selectRaw('SUM(amount) as total_amount')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereBetween($dateColumn, [$start, $end])
            ->groupBy($dateColumn)
            ->orderBy($dateColumn)
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, int>  $target
     */
    private function bucketByMonth($rows, array &$target): void
    {
        foreach ($rows as $row) {
            $key = substr((string) $row->entry_date, 0, 7);
            if (array_key_exists($key, $target)) {
                $target[$key] += Money::toCents((string) $row->total_amount);
            }
        }
    }

    private function recordCount(string $table, string $dateColumn, int $userId, string $start, string $end): int
    {
        return DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereBetween($dateColumn, [$start, $end])
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(int $userId, string $start, string $end): array
    {
        $rows = DB::table('expenses as e')
            ->join('categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.user_id', $userId)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.expense_date', [$start, $end])
            ->select('e.category_id', 'c.name as category_name')
            ->selectRaw('SUM(e.amount) as total_amount')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupBy('e.category_id', 'c.name')
            ->orderByDesc('total_amount')
            ->orderBy('c.name')
            ->get();

        $total = 0;
        foreach ($rows as $row) {
            $total += Money::toCents((string) $row->total_amount);
        }

        return $rows->map(function ($row) use ($total) {
            $cents = Money::toCents((string) $row->total_amount);
            $count = (int) $row->transaction_count;

            return [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'total_spent' => Money::fromCents($cents),
                'transaction_count' => $count,
                'average_transaction' => Money::fromCents($count > 0 ? intdiv($cents, $count) : 0),
                'share_percent' => $total > 0 ? round(($cents / $total) * 100, 2) : 0.0,
            ];
        })->all();
    }

    /**
     * A transaction list is deliberately bounded. It is a "largest" summary,
     * not a replacement for the paginated expense ledger.
     *
     * @return array<int, array<string, mixed>>
     */
    private function largestTransactions(int $userId, string $start, string $end, int $limit = 10): array
    {
        return Expense::query()
            ->with('category')
            ->whereUser($userId)
            ->whereBetween('expense_date', [$start, $end])
            ->orderByDesc('amount')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'description' => $expense->description,
                'amount' => (string) $expense->amount,
                'expense_date' => $expense->expense_date->toDateString(),
                'category_id' => $expense->category_id,
                'category_name' => $expense->category?->name,
            ])
            ->all();
    }

    /**
     * Documented anomaly rule: with at least five expenses in the period, an
     * expense is "unusually large" when it is at least three times the period
     * average. This is intentionally simple and inspectable; it is a screen,
     * not a prediction or recommendation.
     *
     * @return array<string, mixed>
     */
    private function anomalies(
        int $userId,
        string $start,
        string $end,
        int $expenseCount,
        int $expenseCents
    ): array {
        $eligible = $expenseCount >= 5 && $expenseCents > 0;
        $average = $expenseCount > 0 ? intdiv($expenseCents, $expenseCount) : 0;
        $threshold = $average * 3;

        $base = [
            'rule' => 'At least 3× the average expense in the selected period',
            'explanation' => 'Requires at least five non-deleted expenses so a tiny sample does not create noise. This is a descriptive screen, not advice.',
            'minimum_expenses' => 5,
            'multiplier' => 3,
            'expense_count' => $expenseCount,
            'average_expense' => Money::fromCents($average),
            'threshold_amount' => Money::fromCents($threshold),
            'eligible' => $eligible,
        ];

        if (! $eligible) {
            $base['transactions'] = [];
            $base['reason'] = $expenseCount < 5
                ? 'Not enough expense history in this period for a stable comparison.'
                : 'No expense amount is available to calculate a comparison.';

            return $base;
        }

        $base['transactions'] = Expense::query()
            ->with('category')
            ->whereUser($userId)
            ->whereBetween('expense_date', [$start, $end])
            ->where('amount', '>=', Money::fromCents($threshold))
            ->orderByDesc('amount')
            ->orderByDesc('expense_date')
            ->limit(10)
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'description' => $expense->description,
                'amount' => (string) $expense->amount,
                'expense_date' => $expense->expense_date->toDateString(),
                'category_name' => $expense->category?->name,
            ])
            ->all();
        $base['reason'] = $base['transactions'] === []
            ? 'No transactions crossed the documented threshold in this period.'
            : null;

        return $base;
    }

    /**
     * Budget utilization counts only categories that have a budget for that
     * month. Unbudgeted spending is intentionally excluded from this ratio.
     *
     * @return array<string, mixed>
     */
    private function budgetUtilization(int $userId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $budgets = Budget::query()
            ->whereUser($userId)
            ->with('category')
            ->whereBetween('month_year', [
                $start->startOfMonth()->toDateTimeString(),
                $end->endOfMonth()->endOfDay()->toDateTimeString(),
            ])
            ->get();

        if ($budgets->isEmpty()) {
            return [
                'latest_month' => $end->format('Y-m'),
                'summary' => [
                    'budget_count' => 0,
                    'total_limit' => '0.00',
                    'total_spent' => '0.00',
                    'remaining_amount' => '0.00',
                    'utilization_percent' => 0.0,
                    'overspent_count' => 0,
                ],
                'items' => [],
                'history' => [],
                'note' => 'No budgets are set for the selected months.',
            ];
        }

        $categoryIds = $budgets->pluck('category_id')->unique()->values()->all();
        $spendingRows = DB::table('expenses')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('expense_date', [
                $start->startOfMonth()->toDateTimeString(),
                $end->endOfMonth()->endOfDay()->toDateTimeString(),
            ])
            ->select('category_id', 'expense_date')
            ->selectRaw('SUM(amount) as total_amount')
            ->groupBy('category_id', 'expense_date')
            ->get();

        $spendByMonthCategory = [];
        foreach ($spendingRows as $row) {
            $key = substr((string) $row->expense_date, 0, 7).'|'.$row->category_id;
            $spendByMonthCategory[$key] = ($spendByMonthCategory[$key] ?? 0)
                + Money::toCents((string) $row->total_amount);
        }

        $byMonth = [];
        $formatted = [];
        foreach ($budgets as $budget) {
            $month = $budget->month_year->format('Y-m');
            $spent = $spendByMonthCategory[$month.'|'.$budget->category_id] ?? 0;
            $limit = Money::toCents((string) $budget->limit_amount);
            if (! isset($byMonth[$month])) {
                $byMonth[$month] = [
                    'month' => $month,
                    'budget_count' => 0,
                    'limit' => 0,
                    'spent' => 0,
                    'overspent_count' => 0,
                    'items' => [],
                ];
            }
            $byMonth[$month]['budget_count']++;
            $byMonth[$month]['limit'] += $limit;
            $byMonth[$month]['spent'] += $spent;
            if ($spent > $limit) {
                $byMonth[$month]['overspent_count']++;
            }
            $byMonth[$month]['items'][] = [
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
                'category_name' => $budget->category?->name,
                'limit_amount' => Money::fromCents($limit),
                'spent_amount' => Money::fromCents($spent),
                'remaining_amount' => Money::fromCents($limit - $spent),
                'percentage_used' => $limit > 0 ? round(($spent / $limit) * 100, 2) : 0.0,
                'is_overspent' => $spent > $limit,
            ];
        }

        ksort($byMonth);
        foreach ($byMonth as $month => $row) {
            $formatted[] = [
                'month' => $month,
                'budget_count' => $row['budget_count'],
                'total_limit' => Money::fromCents($row['limit']),
                'total_spent' => Money::fromCents($row['spent']),
                'remaining_amount' => Money::fromCents($row['limit'] - $row['spent']),
                'utilization_percent' => $row['limit'] > 0 ? round(($row['spent'] / $row['limit']) * 100, 2) : 0.0,
                'overspent_count' => $row['overspent_count'],
                'items' => $row['items'],
            ];
        }

        $latest = $formatted[count($formatted) - 1];

        return [
            'latest_month' => $latest['month'],
            'summary' => [
                'budget_count' => $latest['budget_count'],
                'total_limit' => $latest['total_limit'],
                'total_spent' => $latest['total_spent'],
                'remaining_amount' => $latest['remaining_amount'],
                'utilization_percent' => $latest['utilization_percent'],
                'overspent_count' => $latest['overspent_count'],
            ],
            'items' => $latest['items'],
            'history' => array_map(function (array $row) {
                unset($row['items']);

                return $row;
            }, $formatted),
            'note' => 'Only categories with a budget for the month are included. Unbudgeted spending is excluded.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recurringSummary(int $userId): array
    {
        $schedules = DB::table('recurring_expenses')
            ->where('user_id', $userId)
            ->get(['id', 'amount', 'frequency', 'status']);

        $counts = ['active' => 0, 'paused' => 0, 'stopped' => 0];
        $monthly = 0;
        $annual = 0;

        foreach ($schedules as $schedule) {
            $counts[$schedule->status] = ($counts[$schedule->status] ?? 0) + 1;
            if ($schedule->status === 'active') {
                $monthly += FrequencyCost::monthlyCents((string) $schedule->amount, $schedule->frequency);
                $annual += FrequencyCost::annualCents((string) $schedule->amount, $schedule->frequency);
            }
        }

        $today = FinancialCalendar::today();
        $upcoming = DB::table('recurring_expense_occurrences as o')
            ->join('recurring_expenses as r', 'r.id', '=', 'o.recurring_expense_id')
            ->where('r.user_id', $userId)
            ->where('r.status', 'active')
            ->where('o.status', 'planned')
            ->whereBetween('o.occurrence_date', [
                $today->startOfDay()->toDateTimeString(),
                $today->addDays(30)->endOfDay()->toDateTimeString(),
            ])
            ->selectRaw('COUNT(*) as expected_count')
            ->selectRaw('COALESCE(SUM(o.expected_amount), 0) as expected_total')
            ->first();

        return [
            'active_count' => $counts['active'],
            'paused_count' => $counts['paused'],
            'stopped_count' => $counts['stopped'],
            'estimated_monthly_cost' => Money::fromCents($monthly),
            'estimated_annual_cost' => Money::fromCents($annual),
            'upcoming_30_days_count' => (int) ($upcoming->expected_count ?? 0),
            'upcoming_30_days_amount' => Money::fromCents(Money::toCents((string) ($upcoming->expected_total ?? 0))),
            'note' => 'Normalized from active schedules only. These are expectations, never actual transactions.',
        ];
    }

    private function changePercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
