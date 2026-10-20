<?php

namespace App\Services;

use App\Models\RecurringExpenseOccurrence;
use App\Models\RecurringIncomeOccurrence;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashFlowForecastService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Transparent schedule-based forecast. Only materialized planned
     * occurrences are projected; no historical averages and no actual ledger
     * rows are added to expected future amounts.
     *
     * @return array<string, mixed>
     */
    public function forUser(int $userId, int $horizon = 30, ?string $asOf = null): array
    {
        if (! in_array($horizon, [30, 60, 90], true)) {
            throw ValidationException::withMessages([
                'horizon' => 'Choose a forecast horizon of 30, 60 or 90 days.',
            ]);
        }

        $start = $asOf !== null
            ? FinancialCalendar::parseDate($asOf, 'as_of')
            : FinancialCalendar::today();
        $end = $start->addDays($horizon - 1);

        $incomeOccurrences = RecurringIncomeOccurrence::query()
            ->with('schedule')
            ->where('status', 'planned')
            ->whereHas('schedule', fn ($schedule) => $schedule
                ->where('user_id', $userId)
                ->where('status', 'active'))
            ->whereBetween('occurrence_date', [
                $start->startOfDay()->toDateTimeString(),
                $end->endOfDay()->toDateTimeString(),
            ])
            ->orderBy('occurrence_date')
            ->orderBy('id')
            ->get();

        $expenseOccurrences = RecurringExpenseOccurrence::query()
            ->with(['schedule.category'])
            ->where('status', 'planned')
            ->whereHas('schedule', fn ($schedule) => $schedule
                ->where('user_id', $userId)
                ->where('status', 'active'))
            ->whereBetween('occurrence_date', [
                $start->startOfDay()->toDateTimeString(),
                $end->endOfDay()->toDateTimeString(),
            ])
            ->orderBy('occurrence_date')
            ->orderBy('id')
            ->get();

        $incomeCents = 0;
        $expenseCents = 0;
        $events = [];

        foreach ($incomeOccurrences as $occurrence) {
            $cents = Money::toCents((string) $occurrence->expected_amount);
            $incomeCents += $cents;
            $events[] = [
                'type' => 'income',
                'date' => $occurrence->occurrence_date->toDateString(),
                'name' => $occurrence->schedule?->name ?? 'Expected recurring income',
                'amount' => Money::fromCents($cents),
                'source' => 'recurring_income',
                'schedule_id' => $occurrence->recurring_income_id,
                'category' => null,
                'certainty' => 'expected',
            ];
        }

        foreach ($expenseOccurrences as $occurrence) {
            $schedule = $occurrence->schedule;
            $cents = Money::toCents((string) $occurrence->expected_amount);
            $expenseCents += $cents;
            $events[] = [
                'type' => 'expense',
                'date' => $occurrence->occurrence_date->toDateString(),
                'name' => $schedule?->description ?? 'Expected recurring bill',
                'amount' => Money::fromCents($cents),
                'source' => 'recurring_expense',
                'schedule_id' => $occurrence->recurring_expense_id,
                'category' => $schedule?->category === null ? null : [
                    'id' => $schedule->category->id,
                    'name' => $schedule->category->name,
                ],
                'certainty' => 'expected',
            ];
        }

        usort($events, function (array $left, array $right) {
            return [$left['date'], $left['type'], $left['name']]
                <=> [$right['date'], $right['type'], $right['name']];
        });

        $monthly = $this->monthlyProjection($events, $start, $end);
        $subscriptionSummary = $this->subscriptions->summaryForUser($userId, $start->toDateString());

        $overdue = DB::table('recurring_expense_occurrences as o')
            ->join('recurring_expenses as r', 'r.id', '=', 'o.recurring_expense_id')
            ->where('r.user_id', $userId)
            ->where('r.status', 'active')
            ->where('o.status', 'planned')
            ->whereDate('o.occurrence_date', '<', $start->toDateString())
            ->selectRaw('COUNT(*) as commitment_count')
            ->selectRaw('COALESCE(SUM(o.expected_amount), 0) as commitment_total')
            ->first();

        $lookbackEnd = $start->startOfMonth()->subDay();
        $lookbackStart = $start->startOfMonth()->subMonthsNoOverflow(3);
        $historicalIncome = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereBetween('income_date', [
                $lookbackStart->startOfDay()->toDateTimeString(),
                $lookbackEnd->endOfDay()->toDateTimeString(),
            ])
            ->sum('amount');
        $historicalExpenses = DB::table('expenses')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [
                $lookbackStart->startOfDay()->toDateTimeString(),
                $lookbackEnd->endOfDay()->toDateTimeString(),
            ])
            ->sum('amount');

        $incomeCounts = $this->scheduleCounts('recurring_incomes', $userId);
        $expenseCounts = $this->scheduleCounts('recurring_expenses', $userId);
        $net = $incomeCents - $expenseCents;

        return [
            'as_of' => $start->toDateString(),
            'horizon_days' => $horizon,
            'window' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'calendar_days' => $horizon,
                'timezone' => FinancialCalendar::TIMEZONE,
                'boundary' => 'Inclusive UTC calendar days beginning with as_of.',
            ],
            'starting_balance' => null,
            'starting_balance_status' => 'unavailable',
            'starting_balance_reason' => 'SpendSense does not store a verified cash-account balance. Net worth valuations and savings goals are not treated as available cash.',
            'expected_income' => Money::fromCents($incomeCents),
            'expected_expenses' => Money::fromCents($expenseCents),
            'projected_net_cash_flow' => Money::fromCents($net),
            'ending_balance' => null,
            'ending_balance_reason' => 'An ending balance would require a trustworthy starting balance. Only projected net cash flow is shown.',
            'negative_cash_flow_dates' => [],
            'negative_cash_flow_status' => 'not_calculable',
            'negative_cash_flow_reason' => 'The date cash becomes negative cannot be calculated without a verified opening balance.',
            'monthly_projection' => $monthly,
            'upcoming_bills' => array_slice(array_values(array_filter(
                $events,
                fn (array $event) => $event['type'] === 'expense'
            )), 0, 20),
            'events' => array_slice($events, 0, 200),
            'event_count' => count($events),
            'events_truncated' => count($events) > 200,
            'expected_income_count' => $incomeOccurrences->count(),
            'expected_expense_count' => $expenseOccurrences->count(),
            'overdue_commitments' => [
                'count' => (int) ($overdue->commitment_count ?? 0),
                'total' => Money::fromCents(Money::toCents((string) ($overdue->commitment_total ?? 0))),
                'included_in_forecast' => false,
                'note' => 'Planned bills dated before the forecast window are shown for awareness but are not re-dated or added to future totals.',
            ],
            'schedule_context' => [
                'recurring_income' => $incomeCounts,
                'recurring_expense' => $expenseCounts,
            ],
            'subscriptions' => [
                'active_count' => $subscriptionSummary['active_count'],
                'estimated_monthly_cost' => $subscriptionSummary['estimated_monthly_cost'],
                'note' => 'Subscription cost is a separate planning summary and is not added to the expected expense total above.',
            ],
            'historical_actuals' => [
                'from' => $lookbackStart->toDateString(),
                'to' => $lookbackEnd->toDateString(),
                'months' => 3,
                'average_monthly_income' => Money::fromCents(intdiv(Money::toCents((string) $historicalIncome), 3)),
                'average_monthly_expenses' => Money::fromCents(intdiv(Money::toCents((string) $historicalExpenses), 3)),
                'note' => 'Recent completed UTC calendar months. Shown as context only; these averages are not projected into the future.',
            ],
            'notes' => [
                'Forecast amounts are expected recurring occurrences, not completed income or expenses.',
                'Paid, skipped and cancelled occurrences are excluded; paused and stopped schedules are excluded by query.',
                'Recurring schedules materialize a bounded future horizon, so forecasts only see occurrences already generated by the existing schedules.',
                'No financial transaction is created, edited or paid by loading this forecast.',
                'Expected values are not guaranteed and may omit unscheduled spending or income.',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function monthlyProjection(array $events, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = FinancialCalendar::monthKeys($start->startOfMonth(), $end->startOfMonth());
        $buckets = [];
        foreach ($keys as $key) {
            $buckets[$key] = ['income' => 0, 'expenses' => 0];
        }

        foreach ($events as $event) {
            $key = substr($event['date'], 0, 7);
            if (! isset($buckets[$key])) {
                continue;
            }
            if ($event['type'] === 'income') {
                $buckets[$key]['income'] += Money::toCents($event['amount']);
            } else {
                $buckets[$key]['expenses'] += Money::toCents($event['amount']);
            }
        }

        $rows = [];
        foreach ($buckets as $key => $bucket) {
            $month = FinancialCalendar::parseMonth($key, 'month');
            $rows[] = [
                'month' => $key,
                'label' => $month->format('F Y'),
                'expected_income' => Money::fromCents($bucket['income']),
                'expected_expenses' => Money::fromCents($bucket['expenses']),
                'projected_net_cash_flow' => Money::fromCents($bucket['income'] - $bucket['expenses']),
                'is_partial' => $key === $start->format('Y-m') || $key === $end->format('Y-m'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function scheduleCounts(string $table, int $userId): array
    {
        $counts = ['active' => 0, 'paused' => 0, 'stopped' => 0];
        $rows = DB::table($table)
            ->where('user_id', $userId)
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->aggregate;
        }

        return $counts;
    }
}
