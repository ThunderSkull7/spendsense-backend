<?php

namespace App\Services;

use App\Models\Expense;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpendingTrendService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(
        int $userId,
        ?string $fromDate = null,
        ?string $toDate = null,
        string $granularity = 'day'
    ): array {
        if (! in_array($granularity, ['day', 'week', 'month'], true)) {
            throw ValidationException::withMessages([
                'granularity' => 'Choose a daily, weekly or monthly trend.',
            ]);
        }

        $to = $toDate !== null
            ? FinancialCalendar::parseDate($toDate, 'to')
            : FinancialCalendar::today();
        $from = $fromDate !== null
            ? FinancialCalendar::parseDate($fromDate, 'from')
            : $to->subDays(89);

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'from' => 'The start date must be on or before the end date.',
            ]);
        }

        $days = $from->diffInDays($to) + 1;
        $maxDays = match ($granularity) {
            'day' => 120,
            'week' => 730,
            default => 731,
        };

        if ($days > $maxDays) {
            $message = $granularity === 'day'
                ? 'Daily trends cover at most 120 days at a time.'
                : 'This trend covers at most about two years at a time.';
            throw ValidationException::withMessages(['to' => $message]);
        }

        $curStart = $from->toDateString();
        $curEnd = $to->toDateString();
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $dailyExpenses = $this->dailyTotals($userId, 'expenses', 'expense_date', $curStart, $curEnd);
        $dailyIncomes = $this->dailyTotals($userId, 'incomes', 'income_date', $curStart, $curEnd);

        $buckets = $this->buckets($from, $to, $granularity, $dailyExpenses, $dailyIncomes);
        $rolling = $this->rollingAverage($buckets, $granularity);

        foreach ($buckets as $index => &$bucket) {
            $bucket['rolling_average_expense'] = $rolling[$index];
        }
        unset($bucket);

        $currentExpenseCents = array_sum(array_column($buckets, 'expense_cents'));
        $currentIncomeCents = array_sum(array_column($buckets, 'income_cents'));
        $previousExpenseCents = $this->total($userId, 'expenses', 'expense_date', 'amount', $previousFrom->toDateString(), $previousTo->toDateString());
        $previousIncomeCents = $this->total($userId, 'incomes', 'income_date', 'amount', $previousFrom->toDateString(), $previousTo->toDateString());

        $categories = $this->categoryComparison($userId, $curStart, $curEnd, $previousFrom->toDateString(), $previousTo->toDateString());
        $expenseRows = $this->expenseRows($userId, $curStart, $curEnd);
        $averageCents = count($expenseRows) > 0
            ? intdiv(array_sum(array_map(fn (array $row): int => $row['amount_cents'], $expenseRows)), count($expenseRows))
            : 0;

        $anomalies = array_values(array_filter(
            $expenseRows,
            fn (array $row): bool => count($expenseRows) >= 5 && $averageCents > 0 && $row['amount_cents'] > $averageCents * 3
        ));
        usort($anomalies, fn (array $a, array $b): int => $b['amount_cents'] <=> $a['amount_cents']);
        $anomalies = array_slice($anomalies, 0, 10);

        $largest = $expenseRows;
        usort($largest, fn (array $a, array $b): int => $b['amount_cents'] <=> $a['amount_cents']);
        $largest = array_slice($largest, 0, 10);

        return [
            'from' => $curStart,
            'to' => $curEnd,
            'granularity' => $granularity,
            'timezone' => FinancialCalendar::TIMEZONE,
            'comparison_period' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'label' => 'Previous period of equal length',
            ],
            'summary' => [
                'expenses_amount' => Money::fromCents($currentExpenseCents),
                'income_amount' => Money::fromCents($currentIncomeCents),
                'net_amount' => Money::fromCents($currentIncomeCents - $currentExpenseCents),
                'bucket_count' => count($buckets),
                'average_expense_per_bucket' => Money::fromCents(
                    count($buckets) > 0 ? intdiv($currentExpenseCents, count($buckets)) : 0
                ),
                'largest_expense' => $largest[0]['amount'] ?? '0.00',
                'anomaly_count' => count($anomalies),
                'has_data' => $currentExpenseCents > 0 || $currentIncomeCents > 0,
            ],
            'comparison' => $this->comparison(
                $currentExpenseCents,
                $previousExpenseCents,
                $currentIncomeCents,
                $previousIncomeCents
            ),
            'buckets' => array_map(fn (array $bucket): array => $this->presentBucket($bucket), $buckets),
            'categories' => $categories,
            'anomalies' => array_map(fn (array $row): array => $this->presentExpense($row), $anomalies),
            'largest_expenses' => array_map(fn (array $row): array => $this->presentExpense($row), $largest),
            'anomaly_rule' => [
                'threshold_amount' => $averageCents > 0 ? Money::fromCents($averageCents * 3) : null,
                'average_expense' => Money::fromCents($averageCents),
                'minimum_transactions' => 5,
                'note' => 'Large-expense review flags amounts above three times the average expense in the selected period when at least five expenses exist. It is not a fraud finding.',
            ],
            'notes' => [
                'Trends exclude soft-deleted expenses and incomes.',
                'Savings contributions are internal tracking entries and are not included as expenses.',
                'Week buckets start on Monday; month buckets use inclusive UTC calendar months.',
                'The previous comparison period has the same number of calendar days immediately before the selected range.',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dailyTotals(int $userId, string $table, string $dateColumn, string $from, string $to): array
    {
        $rows = DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to)
            ->selectRaw("{$dateColumn} as day, SUM(amount) as total")
            ->groupBy($dateColumn)
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[substr((string) $row->day, 0, 10)] = Money::toCents((string) $row->total);
        }

        return $totals;
    }

    private function total(int $userId, string $table, string $dateColumn, string $sumColumn, string $from, string $to): int
    {
        $total = DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to)
            ->sum($sumColumn);

        return Money::toCents((string) $total);
    }

    /**
     * @param  array<string, int>  $expenses
     * @param  array<string, int>  $incomes
     * @return list<array<string, mixed>>
     */
    private function buckets(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $granularity,
        array $expenses,
        array $incomes
    ): array {
        $buckets = [];

        if ($granularity === 'day') {
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $key = $day->toDateString();
                $buckets[] = $this->bucket($key, $key, $key, $expenses[$key] ?? 0, $incomes[$key] ?? 0, false);
            }

            return $buckets;
        }

        if ($granularity === 'week') {
            $cursor = $from->startOfWeek(CarbonImmutable::MONDAY);

            while ($cursor->lte($to)) {
                $start = $cursor->lt($from) ? $from : $cursor;
                $end = $cursor->addDays(6)->gt($to) ? $to : $cursor->addDays(6);
                $expenseCents = 0;
                $incomeCents = 0;

                for ($day = $cursor; $day->lte($cursor->addDays(6)); $day = $day->addDay()) {
                    $key = $day->toDateString();
                    $expenseCents += $expenses[$key] ?? 0;
                    $incomeCents += $incomes[$key] ?? 0;
                }

                $buckets[] = $this->bucket(
                    $cursor->toDateString(),
                    $start->toDateString(),
                    $end->toDateString(),
                    $expenseCents,
                    $incomeCents,
                    $start->ne($cursor) || $end->ne($cursor->addDays(6))
                );
                $cursor = $cursor->addWeek();
            }

            return $buckets;
        }

        $cursor = $from->startOfMonth();

        while ($cursor->lte($to)) {
            $start = $cursor->lt($from) ? $from : $cursor;
            $monthEnd = $cursor->endOfMonth();
            $end = $monthEnd->gt($to) ? $to : $monthEnd;
            $expenseCents = 0;
            $incomeCents = 0;

            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $key = $day->toDateString();
                $expenseCents += $expenses[$key] ?? 0;
                $incomeCents += $incomes[$key] ?? 0;
            }

            $buckets[] = $this->bucket(
                $cursor->format('Y-m'),
                $start->toDateString(),
                $end->toDateString(),
                $expenseCents,
                $incomeCents,
                $start->ne($cursor->startOfMonth()) || $end->ne($monthEnd)
            );
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $buckets;
    }

    /**
     * @return array<string, mixed>
     */
    private function bucket(
        string $key,
        string $start,
        string $end,
        int $expenseCents,
        int $incomeCents,
        bool $partial
    ): array {
        return [
            'key' => $key,
            'start' => $start,
            'end' => $end,
            'expense_cents' => $expenseCents,
            'income_cents' => $incomeCents,
            'net_cents' => $incomeCents - $expenseCents,
            'is_partial' => $partial,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $buckets
     * @return list<string|null>
     */
    private function rollingAverage(array $buckets, string $granularity): array
    {
        $window = match ($granularity) {
            'day' => 7,
            'week' => 4,
            default => 3,
        };

        $rolling = [];

        foreach ($buckets as $index => $bucket) {
            if ($index + 1 < $window) {
                $rolling[] = null;

                continue;
            }

            $slice = array_slice($buckets, $index - $window + 1, $window);
            $rolling[] = Money::fromCents(intdiv(
                array_sum(array_column($slice, 'expense_cents')),
                $window
            ));
        }

        return $rolling;
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function presentBucket(array $bucket): array
    {
        return [
            'key' => $bucket['key'],
            'start' => $bucket['start'],
            'end' => $bucket['end'],
            'expenses_amount' => Money::fromCents($bucket['expense_cents']),
            'income_amount' => Money::fromCents($bucket['income_cents']),
            'net_amount' => Money::fromCents($bucket['net_cents']),
            'rolling_average_expense' => $bucket['rolling_average_expense'],
            'is_partial' => $bucket['is_partial'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(int $currentExpense, int $previousExpense, int $currentIncome, int $previousIncome): array
    {
        return [
            'expenses' => $this->change($currentExpense, $previousExpense),
            'income' => $this->change($currentIncome, $previousIncome),
            'net' => [
                'current_amount' => Money::fromCents($currentIncome - $currentExpense),
                'previous_amount' => Money::fromCents($previousIncome - $previousExpense),
                'change_amount' => Money::fromCents(($currentIncome - $currentExpense) - ($previousIncome - $previousExpense)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function change(int $current, int $previous): array
    {
        $change = $current - $previous;

        return [
            'current_amount' => Money::fromCents($current),
            'previous_amount' => Money::fromCents($previous),
            'change_amount' => Money::fromCents($change),
            'change_percent' => $previous > 0 ? round(($change / $previous) * 100, 1) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryComparison(int $userId, string $from, string $to, string $previousFrom, string $previousTo): array
    {
        $current = $this->categoryTotals($userId, $from, $to);
        $previous = $this->categoryTotals($userId, $previousFrom, $previousTo);
        $totalCurrent = array_sum(array_column($current, 'cents'));
        $keys = array_unique(array_merge(array_keys($current), array_keys($previous)));
        $rows = [];

        foreach ($keys as $key) {
            $categoryId = (int) $key;
            $currentCents = $current[$categoryId]['cents'] ?? 0;
            $previousCents = $previous[$categoryId]['cents'] ?? 0;
            $delta = $currentCents - $previousCents;
            $name = $current[$categoryId]['name'] ?? $previous[$categoryId]['name'] ?? 'Deleted category';

            $rows[] = [
                'category_id' => $categoryId,
                'category_name' => $name,
                'amount' => Money::fromCents($currentCents),
                'previous_amount' => Money::fromCents($previousCents),
                'change_amount' => Money::fromCents($delta),
                'change_percent' => $previousCents > 0 ? round(($delta / $previousCents) * 100, 1) : null,
                'share_percent' => $totalCurrent > 0 ? round(($currentCents / $totalCurrent) * 100, 1) : null,
                'transaction_count' => $current[$categoryId]['count'] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => Money::toCents($b['amount']) <=> Money::toCents($a['amount']));

        return $rows;
    }

    /**
     * @return array<int, array{name: string, cents: int, count: int}>
     */
    private function categoryTotals(int $userId, string $from, string $to): array
    {
        $rows = DB::table('expenses as e')
            ->leftJoin('categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.user_id', $userId)
            ->whereNull('e.deleted_at')
            ->whereDate('e.expense_date', '>=', $from)->whereDate('e.expense_date', '<=', $to)
            ->selectRaw('e.category_id, COALESCE(c.name, ?) as category_name, SUM(e.amount) as total, COUNT(*) as row_count', ['Deleted category'])
            ->groupBy('e.category_id', 'c.name')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $categoryId = (int) $row->category_id;
            $totals[$categoryId] = [
                'name' => $row->category_name,
                'cents' => Money::toCents((string) $row->total),
                'count' => (int) $row->row_count,
            ];
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expenseRows(int $userId, string $from, string $to): array
    {
        return Expense::query()
            ->whereUser($userId)
            ->with('category:id,name')
            ->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->get(['id', 'amount', 'expense_date', 'description', 'category_id'])
            ->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'amount' => (string) $expense->amount,
                'amount_cents' => Money::toCents((string) $expense->amount),
                'expense_date' => $expense->expense_date->format('Y-m-d'),
                'description' => $expense->description,
                'category_id' => $expense->category_id,
                'category_name' => $expense->category?->name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentExpense(array $row): array
    {
        unset($row['amount_cents']);

        return $row;
    }
}
