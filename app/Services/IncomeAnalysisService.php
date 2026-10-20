<?php

namespace App\Services;

use App\Support\DateExpressions;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class IncomeAnalysisService
{
    /**
     * Income is grouped by source because the Stage 1 schema has no income
     * category. Recurring-income occurrences are expectations and are kept
     * separate from actual received income.
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
        $months = FinancialCalendar::monthKeys($start, $end->endOfMonth());
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subMonthsNoOverflow(count($months) - 1)->startOfMonth();

        $monthly = $this->monthlyActuals($userId, $startDate, $endDate, $months);
        $scheduled = $this->scheduledByMonth($userId, $startDate, $endDate, $months);

        foreach ($monthly as $index => &$row) {
            $row['scheduled_expected_amount'] = $scheduled[$row['month']]['expected'] ?? '0.00';
            $row['scheduled_received_amount'] = $scheduled[$row['month']]['received'] ?? '0.00';
            $row['planned_unreceived_amount'] = $scheduled[$row['month']]['planned'] ?? '0.00';
            $row['unplanned_actual_amount'] = Money::fromCents(
                Money::toCents($row['actual_amount'])
                - Money::toCents($scheduled[$row['month']]['received'] ?? '0.00')
            );
            $row['has_income'] = Money::toCents($row['actual_amount']) > 0;
        }
        unset($row);

        $sources = $this->sourceComparison($userId, $startDate, $endDate, $previousStart->toDateString(), $previousEnd->toDateString());
        $frequencies = $this->frequencyBreakdown($userId, $startDate, $endDate);

        $actualCents = array_sum(array_map(fn (array $row): int => Money::toCents($row['actual_amount']), $monthly));
        $previousCents = $this->actualTotal($userId, $previousStart->toDateString(), $previousEnd->toDateString());
        $scheduledExpected = array_sum(array_map(fn (array $row): int => Money::toCents($row['scheduled_expected_amount']), $monthly));
        $scheduledReceived = array_sum(array_map(fn (array $row): int => Money::toCents($row['scheduled_received_amount']), $monthly));
        $plannedUnreceived = array_sum(array_map(fn (array $row): int => Money::toCents($row['planned_unreceived_amount']), $monthly));
        $unplanned = $actualCents - $scheduledReceived;

        $monthsWithIncome = count(array_filter($monthly, fn (array $row): bool => Money::toCents($row['actual_amount']) > 0));
        $amounts = array_map(fn (array $row): int => Money::toCents($row['actual_amount']), $monthly);
        $nonZero = array_values(array_filter($amounts, fn (int $amount): bool => $amount > 0));
        $average = count($nonZero) > 0 ? intdiv(array_sum($nonZero), count($nonZero)) : 0;
        $variance = 0;

        if ($average > 0 && count($nonZero) > 0) {
            $squared = array_sum(array_map(fn (int $amount): float => ($amount - $average) ** 2, $nonZero));
            $variance = (int) round(sqrt($squared / count($nonZero)));
        }

        $change = $actualCents - $previousCents;

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'timezone' => FinancialCalendar::TIMEZONE,
            'comparison_period' => [
                'from' => $previousStart->format('Y-m'),
                'to' => $previousEnd->format('Y-m'),
                'label' => 'Previous '.count($months).' calendar months',
            ],
            'summary' => [
                'actual_income_amount' => Money::fromCents($actualCents),
                'previous_income_amount' => Money::fromCents($previousCents),
                'change_amount' => Money::fromCents($change),
                'change_percent' => $previousCents > 0 ? round(($change / $previousCents) * 100, 1) : null,
                'scheduled_expected_amount' => Money::fromCents($scheduledExpected),
                'scheduled_received_amount' => Money::fromCents($scheduledReceived),
                'planned_unreceived_amount' => Money::fromCents($plannedUnreceived),
                'unplanned_actual_amount' => Money::fromCents($unplanned),
                'source_count' => count($sources),
                'months_with_income' => $monthsWithIncome,
                'month_count' => count($months),
                'average_month_with_income' => Money::fromCents($average),
                'variability_amount' => Money::fromCents($variance),
                'variability_percent' => $average > 0 ? round(($variance / $average) * 100, 1) : null,
                'has_income_data' => $actualCents > 0,
            ],
            'monthly' => $monthly,
            'sources' => $sources,
            'frequencies' => $frequencies,
            'notes' => [
                'Income has no category field in this version, so analysis uses the source and frequency recorded on each income entry.',
                'Scheduled expected income comes only from already-materialised recurring-income occurrences. Expected amounts are never added to actual income totals.',
                'Received scheduled amounts are the actual linked incomes; unplanned actual income is the remainder of the actual total.',
                'Soft-deleted incomes are excluded from actual totals.',
                'Variability is a descriptive standard deviation across months with income, not a forecast.',
            ],
        ];
    }

    /**
     * @param  list<string>  $months
     * @return list<array<string, mixed>>
     */
    private function monthlyActuals(int $userId, string $from, string $to, array $months): array
    {
        $monthExpression = DateExpressions::month('income_date');

        $rows = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to)
            ->selectRaw("{$monthExpression} as month_key, SUM(amount) as total")
            ->groupByRaw($monthExpression)
            ->get()
            ->keyBy('month_key');

        return array_map(function (string $month) use ($rows): array {
            return [
                'month' => $month,
                'actual_amount' => Money::fromCents(Money::toCents((string) ($rows[$month]->total ?? 0))),
                'scheduled_expected_amount' => '0.00',
                'scheduled_received_amount' => '0.00',
                'planned_unreceived_amount' => '0.00',
                'unplanned_actual_amount' => '0.00',
                'has_income' => false,
            ];
        }, $months);
    }

    /**
     * @param  list<string>  $months
     * @return array<string, array{expected: string, received: string, planned: string}>
     */
    private function scheduledByMonth(int $userId, string $from, string $to, array $months): array
    {
        $monthExpression = DateExpressions::month('o.occurrence_date');

        $rows = DB::table('recurring_income_occurrences as o')
            ->join('recurring_incomes as r', 'r.id', '=', 'o.recurring_income_id')
            ->leftJoin('incomes as i', 'i.id', '=', 'o.income_id')
            ->where('r.user_id', $userId)
            ->where('o.status', '!=', 'cancelled')
            ->whereBetween('o.occurrence_date', [$from, $to])
            ->selectRaw(
                "{$monthExpression} as month_key, "
                .'SUM(o.expected_amount) as expected, '
                ."SUM(CASE WHEN o.status = 'received' THEN COALESCE(i.amount, 0) ELSE 0 END) as received, "
                ."SUM(CASE WHEN o.status = 'planned' THEN o.expected_amount ELSE 0 END) as planned"
            )
            ->groupByRaw($monthExpression)
            ->get()
            ->keyBy('month_key');

        $payload = [];

        foreach ($months as $month) {
            $row = $rows[$month] ?? null;
            $payload[$month] = [
                'expected' => Money::fromCents(Money::toCents((string) ($row->expected ?? 0))),
                'received' => Money::fromCents(Money::toCents((string) ($row->received ?? 0))),
                'planned' => Money::fromCents(Money::toCents((string) ($row->planned ?? 0))),
            ];
        }

        return $payload;
    }

    private function actualTotal(int $userId, string $from, string $to): int
    {
        return Money::toCents((string) DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to)
            ->sum('amount'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceComparison(int $userId, string $from, string $to, string $previousFrom, string $previousTo): array
    {
        $current = $this->sourceTotals($userId, $from, $to);
        $previous = $this->sourceTotals($userId, $previousFrom, $previousTo);
        $keys = array_unique(array_merge(array_keys($current), array_keys($previous)));
        $rows = [];

        foreach ($keys as $key) {
            $currentCents = $current[$key]['cents'] ?? 0;
            $previousCents = $previous[$key]['cents'] ?? 0;
            $delta = $currentCents - $previousCents;

            $rows[] = [
                'source' => $key,
                'actual_amount' => Money::fromCents($currentCents),
                'previous_amount' => Money::fromCents($previousCents),
                'change_amount' => Money::fromCents($delta),
                'change_percent' => $previousCents > 0 ? round(($delta / $previousCents) * 100, 1) : null,
                'transaction_count' => $current[$key]['count'] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => Money::toCents($b['actual_amount']) <=> Money::toCents($a['actual_amount']));

        return $rows;
    }

    /**
     * @return array<string, array{cents: int, count: int}>
     */
    private function sourceTotals(int $userId, string $from, string $to): array
    {
        $rows = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to)
            ->selectRaw('source, SUM(amount) as total, COUNT(*) as row_count')
            ->groupBy('source')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->source] = [
                'cents' => Money::toCents((string) $row->total),
                'count' => (int) $row->row_count,
            ];
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frequencyBreakdown(int $userId, string $from, string $to): array
    {
        $rows = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to)
            ->selectRaw("COALESCE(NULLIF(frequency, ''), 'one-time') as label, COUNT(*) as row_count, SUM(amount) as total")
            ->groupByRaw("COALESCE(NULLIF(frequency, ''), 'one-time')")
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn (object $row): array => [
            'frequency' => (string) $row->label,
            'transaction_count' => (int) $row->row_count,
            'amount' => Money::fromCents(Money::toCents((string) $row->total)),
        ])->all();
    }
}
