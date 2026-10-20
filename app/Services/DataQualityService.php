<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\Subscription;
use App\Support\FinancialCalendar;
use App\Support\Money;

class DataQualityService
{
    private const SAMPLE_LIMIT = 20;

    /**
     * Deterministic review signals only. Nothing is corrected, merged or
     * deleted, and no row is labelled fraudulent.
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

        $startDate = $start->toDateString();
        $endDate = $end->endOfMonth()->toDateString();

        $expenses = Expense::query()
            ->whereUser($userId)
            ->whereDate('expense_date', '>=', $startDate)->whereDate('expense_date', '<=', $endDate)
            ->get(['id', 'amount', 'expense_date', 'category_id', 'description']);

        $incomes = Income::query()
            ->whereUser($userId)
            ->whereDate('income_date', '>=', $startDate)->whereDate('income_date', '<=', $endDate)
            ->get(['id', 'amount', 'income_date', 'source', 'notes']);

        $duplicateExpenses = $this->duplicateExpenses($expenses->all());
        $duplicateIncomes = $this->duplicateIncomes($incomes->all());
        $missingDescriptions = $expenses
            ->filter(fn (Expense $expense): bool => trim((string) $expense->description) === '')
            ->sortByDesc('expense_date')
            ->values();

        $averageCents = $expenses->isEmpty()
            ? 0
            : intdiv(array_sum($expenses->map(fn (Expense $expense): int => Money::toCents((string) $expense->amount))->all()), $expenses->count());

        $large = $expenses
            ->filter(fn (Expense $expense): bool => $expenses->count() >= 5
                && $averageCents > 0
                && Money::toCents((string) $expense->amount) > $averageCents * 3)
            ->sortByDesc(fn (Expense $expense): int => Money::toCents((string) $expense->amount))
            ->values();

        $today = FinancialCalendar::today();

        $schedulesPastEnd = RecurringExpense::query()
            ->whereUser($userId)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today->toDateString())
            ->with('category:id,name')
            ->orderBy('end_date')
            ->get();

        $scheduleIds = RecurringExpense::query()
            ->whereUser($userId)
            ->pluck('id')
            ->all();

        $subscriptions = Subscription::query()
            ->whereUser($userId)
            ->whereNotNull('recurring_expense_id')
            ->whereIn('recurring_expense_id', $scheduleIds)
            ->with(['recurringExpense:id,status,description'])
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->status === 'active'
                && $subscription->recurringExpense !== null
                && $subscription->recurringExpense->status !== 'active');

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'as_of' => $today->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
            'counts' => [
                'duplicate_expense_groups' => count($duplicateExpenses),
                'duplicate_expense_rows' => array_sum(array_column($duplicateExpenses, 'count')),
                'duplicate_income_groups' => count($duplicateIncomes),
                'duplicate_income_rows' => array_sum(array_column($duplicateIncomes, 'count')),
                'missing_descriptions' => $missingDescriptions->count(),
                'large_expenses' => $large->count(),
                'schedules_active_past_end' => $schedulesPastEnd->count(),
                'linked_subscription_status_issues' => $subscriptions->count(),
            ],
            'duplicate_expenses' => $duplicateExpenses,
            'duplicate_incomes' => $duplicateIncomes,
            'missing_descriptions' => $missingDescriptions
                ->take(self::SAMPLE_LIMIT)
                ->map(fn (Expense $expense): array => $this->expenseRow($expense))
                ->all(),
            'large_expenses' => $large
                ->take(self::SAMPLE_LIMIT)
                ->map(fn (Expense $expense): array => $this->expenseRow($expense))
                ->all(),
            'large_expense_threshold' => $averageCents > 0 ? Money::fromCents($averageCents * 3) : null,
            'large_expense_average' => Money::fromCents($averageCents),
            'schedules_past_end' => $schedulesPastEnd
                ->take(self::SAMPLE_LIMIT)
                ->map(fn (RecurringExpense $schedule): array => [
                    'id' => $schedule->id,
                    'description' => $schedule->description,
                    'category_name' => $schedule->category?->name,
                    'amount' => (string) $schedule->amount,
                    'end_date' => $schedule->end_date?->format('Y-m-d'),
                    'status' => $schedule->status,
                    'reason' => 'The schedule is active but its end date has passed. Review whether to stop or extend it.',
                ])
                ->all(),
            'subscription_status_issues' => $subscriptions
                ->take(self::SAMPLE_LIMIT)
                ->map(fn (Subscription $subscription): array => [
                    'subscription_id' => $subscription->id,
                    'name' => $subscription->name,
                    'status' => $subscription->status,
                    'recurring_expense_id' => $subscription->recurring_expense_id,
                    'recurring_expense_status' => $subscription->recurringExpense?->status,
                    'reason' => 'The subscription is active but its linked bill schedule is not active.',
                ])
                ->all(),
            'category_coverage' => [
                'expense_missing_category' => 0,
                'category_required_for_expenses' => true,
                'income_categories_supported' => false,
                'note' => 'Expenses require a category in the Stage 1 schema. Income is grouped by source; income category analysis is not available.',
            ],
            'import_validation' => [
                'status' => 'not_available',
                'note' => 'Bulk import and import-row validation are not part of Stage 5. No import data was reviewed.',
            ],
            'notes' => [
                'Duplicate candidates are rows with the same date, amount and normalized description (or income source/notes). They may be legitimate repeated purchases.',
                'Large expenses are compared with the average expense in the selected period and are not labelled fraudulent.',
                'Categories cannot be missing on an expense because category_id is required. Income has no category field in this version.',
                'Review signals never edit or delete a financial record automatically.',
            ],
            'has_issues' => count($duplicateExpenses) > 0
                || count($duplicateIncomes) > 0
                || $missingDescriptions->isNotEmpty()
                || $large->isNotEmpty()
                || $schedulesPastEnd->isNotEmpty()
                || $subscriptions->isNotEmpty(),
        ];
    }

    /**
     * @param  list<Expense>  $expenses
     * @return list<array<string, mixed>>
     */
    private function duplicateExpenses(array $expenses): array
    {
        $groups = [];

        foreach ($expenses as $expense) {
            $key = implode('|', [
                (string) $expense->amount,
                $expense->expense_date->format('Y-m-d'),
                (string) $expense->category_id,
                $this->normalize($expense->description),
            ]);

            $groups[$key][] = $expense;
        }

        return $this->duplicatePayload($groups, 'expense');
    }

    /**
     * @param  list<Income>  $incomes
     * @return list<array<string, mixed>>
     */
    private function duplicateIncomes(array $incomes): array
    {
        $groups = [];

        foreach ($incomes as $income) {
            $key = implode('|', [
                (string) $income->amount,
                $income->income_date->format('Y-m-d'),
                $this->normalize($income->source),
                $this->normalize($income->notes),
            ]);

            $groups[$key][] = $income;
        }

        return $this->duplicatePayload($groups, 'income');
    }

    /**
     * @param  array<string, array<int, Expense|Income>>  $groups
     * @return list<array<string, mixed>>
     */
    private function duplicatePayload(array $groups, string $type): array
    {
        $duplicates = [];

        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $first = $rows[0];
            $duplicates[] = [
                'type' => $type,
                'transaction_date' => $first instanceof Expense
                    ? $first->expense_date->format('Y-m-d')
                    : $first->income_date->format('Y-m-d'),
                'amount' => (string) $first->amount,
                'label' => $first instanceof Expense ? ($first->description ?: 'No description') : $first->source,
                'count' => count($rows),
                'total_amount' => Money::fromCents(array_sum(array_map(
                    fn (Expense|Income $row): int => Money::toCents((string) $row->amount),
                    $rows
                ))),
                'ids' => array_map(fn (Expense|Income $row): int => $row->id, $rows),
            ];
        }

        usort($duplicates, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($duplicates, 0, self::SAMPLE_LIMIT);
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRow(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_date' => $expense->expense_date->format('Y-m-d'),
            'amount' => (string) $expense->amount,
            'description' => $expense->description,
            'category_id' => $expense->category_id,
        ];
    }
}
