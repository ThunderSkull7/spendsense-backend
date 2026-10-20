<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Expense;
use App\Support\DateExpressions;
use App\Support\FinancialCalendar;
use App\Support\Money;

class BudgetPerformanceService
{
    /**
     * Budget performance across whole calendar months. Only categories with a
     * budget in a given month are included in that month's totals; actual
     * expenses are never reclassified.
     *
     * @return array<string, mixed>
     */
    public function forUser(int $userId, ?string $fromMonth = null, ?string $toMonth = null): array
    {
        $end = $toMonth !== null
            ? FinancialCalendar::parseMonth($toMonth, 'to')
            : FinancialCalendar::today()->startOfMonth();
        $start = $fromMonth !== null
            ? FinancialCalendar::parseMonth($fromMonth, 'from')
            : $end->subMonthsNoOverflow(5)->startOfMonth();

        FinancialCalendar::assertMonthRange($start, $end);

        $months = FinancialCalendar::monthKeys($start, $end->endOfMonth());
        $rangeStart = $start->toDateString();
        $rangeEnd = $end->endOfMonth()->toDateString();

        $budgets = Budget::query()
            ->whereUser($userId)
            ->with('category:id,name')
            ->whereBetween('month_year', [$rangeStart, $rangeEnd])
            ->orderBy('month_year')
            ->orderBy('category_id')
            ->get();

        $categoryIds = $budgets->pluck('category_id')->unique()->values()->all();

        $spend = [];

        if ($categoryIds !== []) {
            $monthExpression = DateExpressions::month('expense_date');

            $rows = Expense::query()
                ->whereUser($userId)
                ->whereDate('expense_date', '>=', $rangeStart)->whereDate('expense_date', '<=', $rangeEnd)
                ->whereIn('category_id', $categoryIds)
                ->selectRaw("category_id, {$monthExpression} as month_key, SUM(amount) as total")
                ->groupBy('category_id')
                ->groupByRaw($monthExpression)
                ->get();

            foreach ($rows as $row) {
                $key = (string) $row->month_key;
                $categoryId = (int) $row->category_id;
                $spend[$key][$categoryId] = ($spend[$key][$categoryId] ?? 0)
                    + Money::toCents((string) $row->total);
            }
        }

        $items = [];
        $monthSummary = [];

        foreach ($months as $month) {
            $monthSummary[$month] = [
                'month' => $month,
                'budgeted_cents' => 0,
                'spent_cents' => 0,
                'budget_count' => 0,
                'overspent_count' => 0,
            ];
        }

        foreach ($budgets as $budget) {
            $month = $budget->month_year->format('Y-m');
            $limit = Money::toCents((string) $budget->limit_amount);
            $spent = $spend[$month][$budget->category_id] ?? 0;
            $remaining = $limit - $spent;
            $thresholds = $this->thresholds($budget->alert_thresholds);
            $nearThreshold = $thresholds === [] ? 80 : max($thresholds);
            $utilization = $limit > 0 ? round(($spent / $limit) * 100, 1) : null;
            $status = match (true) {
                $spent > $limit => 'over',
                $utilization !== null && $utilization >= $nearThreshold => 'near_limit',
                default => 'on_track',
            };

            if (! isset($monthSummary[$month])) {
                $monthSummary[$month] = [
                    'month' => $month,
                    'budgeted_cents' => 0,
                    'spent_cents' => 0,
                    'budget_count' => 0,
                    'overspent_count' => 0,
                ];
            }

            $monthSummary[$month]['budgeted_cents'] += $limit;
            $monthSummary[$month]['spent_cents'] += $spent;
            $monthSummary[$month]['budget_count']++;
            $monthSummary[$month]['overspent_count'] += $spent > $limit ? 1 : 0;

            $items[] = [
                'budget_id' => $budget->id,
                'month' => $month,
                'category_id' => $budget->category_id,
                'category_name' => $budget->category?->name ?? 'Deleted category',
                'limit_amount' => Money::fromCents($limit),
                'spent_amount' => Money::fromCents($spent),
                'remaining_amount' => Money::fromCents($remaining),
                'utilization_percent' => $utilization,
                'status' => $status,
                'overspent_by' => $spent > $limit ? Money::fromCents($spent - $limit) : '0.00',
                'alert_thresholds' => $thresholds,
                'is_current_month' => $month === FinancialCalendar::today()->format('Y-m'),
            ];
        }

        usort($items, function (array $a, array $b): int {
            return [$a['month'], $a['category_name']] <=> [$b['month'], $b['category_name']];
        });

        $history = [];
        foreach ($monthSummary as $month => $summary) {
            $remaining = $summary['budgeted_cents'] - $summary['spent_cents'];
            $history[] = [
                'month' => $month,
                'budgeted_amount' => Money::fromCents($summary['budgeted_cents']),
                'spent_amount' => Money::fromCents($summary['spent_cents']),
                'remaining_amount' => Money::fromCents($remaining),
                'utilization_percent' => $summary['budgeted_cents'] > 0
                    ? round(($summary['spent_cents'] / $summary['budgeted_cents']) * 100, 1)
                    : null,
                'budget_count' => $summary['budget_count'],
                'overspent_count' => $summary['overspent_count'],
            ];
        }

        $latestMonth = $end->format('Y-m');
        $latest = collect($history)->firstWhere('month', $latestMonth) ?? [
            'month' => $latestMonth,
            'budgeted_amount' => '0.00',
            'spent_amount' => '0.00',
            'remaining_amount' => '0.00',
            'utilization_percent' => null,
            'budget_count' => 0,
            'overspent_count' => 0,
        ];

        $periodBudget = array_sum(array_column($history, 'budgeted_amount'));
        $periodSpent = array_sum(array_column($history, 'spent_amount'));
        // Amounts are normalized to strings above; sum in cents for accuracy.
        $budgetCents = array_sum(array_map(
            fn (array $row): int => Money::toCents($row['budgeted_amount']),
            $history
        ));
        $spentCents = array_sum(array_map(
            fn (array $row): int => Money::toCents($row['spent_amount']),
            $history
        ));

        $repeated = $this->repeatedOverspending($items);
        $alerts = $this->alerts($userId, $start->toDateString(), $rangeEnd);

        $thresholds = array_values(array_unique(array_merge(
            ...array_map(fn (array $item): array => $item['alert_thresholds'], $items)
        )));
        sort($thresholds);

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'timezone' => FinancialCalendar::TIMEZONE,
            'summary' => [
                'latest_month' => $latestMonth,
                'latest' => $latest,
                'budgeted_amount' => Money::fromCents($budgetCents),
                'spent_amount' => Money::fromCents($spentCents),
                'remaining_amount' => Money::fromCents($budgetCents - $spentCents),
                'budget_rows' => count($items),
                'overspent_rows' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'over')),
                'near_limit_rows' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'near_limit')),
                'categories_with_budgets' => count(array_unique(array_column($items, 'category_id'))),
                'has_budget_data' => $items !== [],
                'thresholds_seen' => $thresholds,
            ],
            'items' => $items,
            'history' => $history,
            'repeated_overspending' => $repeated,
            'threshold_events' => $alerts,
            'notes' => [
                'Only expenses in categories that have a budget for the same calendar month are included in that month\'s budget totals.',
                'Budget alerts are shown as a separate persisted history; they never change the budget or expense amounts.',
                'Months use inclusive UTC calendar boundaries; no per-user timezone is stored.',
            ],
        ];
    }

    /**
     * @param  array<int, int>|null  $thresholds
     * @return list<int>
     */
    private function thresholds(?array $thresholds): array
    {
        $values = array_values(array_filter(array_map('intval', $thresholds ?? []), fn (int $value): bool => $value >= 1 && $value <= 100));
        sort($values);

        return array_values(array_unique($values));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function repeatedOverspending(array $items): array
    {
        $byCategory = [];

        foreach ($items as $item) {
            $categoryId = $item['category_id'];
            $byCategory[$categoryId] ??= [
                'category_id' => $categoryId,
                'category_name' => $item['category_name'],
                'overspent_months' => 0,
                'budgeted_months' => 0,
                'total_over' => 0,
                'months' => [],
            ];

            $byCategory[$categoryId]['budgeted_months']++;

            if ($item['status'] === 'over') {
                $byCategory[$categoryId]['overspent_months']++;
                $byCategory[$categoryId]['total_over'] += Money::toCents($item['overspent_by']);
                $byCategory[$categoryId]['months'][] = $item['month'];
            }
        }

        $repeated = array_values(array_filter(
            $byCategory,
            fn (array $row): bool => $row['overspent_months'] >= 2
        ));

        usort($repeated, fn (array $a, array $b): int => $b['overspent_months'] <=> $a['overspent_months']);

        return array_map(function (array $row): array {
            $row['total_over'] = Money::fromCents($row['total_over']);
            $row['overspent_share'] = $row['budgeted_months'] > 0
                ? round(($row['overspent_months'] / $row['budgeted_months']) * 100, 1)
                : null;

            return $row;
        }, array_slice($repeated, 0, 12));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alerts(int $userId, string $start, string $end): array
    {
        return BudgetAlert::query()
            ->whereUser($userId)
            ->whereBetween('period_month', [$start, $end])
            ->orderByDesc('period_month')
            ->orderByDesc('threshold')
            ->limit(50)
            ->get()
            ->map(fn (BudgetAlert $alert): array => [
                'id' => $alert->id,
                'budget_id' => $alert->budget_id,
                'threshold' => $alert->threshold,
                'period_month' => $alert->period_month->format('Y-m'),
                'spent_amount' => (string) $alert->spent_amount,
                'limit_amount' => (string) $alert->limit_amount,
                'read_at' => $alert->read_at?->toIso8601String(),
            ])
            ->all();
    }
}
