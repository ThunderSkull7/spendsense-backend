<?php

namespace App\Services;

use App\Models\RecurringExpense;
use App\Models\RecurringExpenseOccurrence;
use App\Models\Subscription;
use App\Support\FinancialCalendar;
use App\Support\FrequencyCost;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CommitmentService
{
    public const UPCOMING_DAYS = 60;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * One combined view of scheduled bills and standalone subscription
     * estimates. A linked subscription is represented by its bill schedule and
     * is never added a second time.
     *
     * @return array<string, mixed>
     */
    public function forUser(
        int $userId,
        ?string $fromMonth = null,
        ?string $toMonth = null,
        ?string $asOfDate = null
    ): array {
        $asOf = $asOfDate !== null
            ? FinancialCalendar::parseDate($asOfDate, 'as_of')
            : FinancialCalendar::today();
        $end = $toMonth !== null
            ? FinancialCalendar::parseMonth($toMonth, 'to')
            : $asOf->startOfMonth();
        $start = $fromMonth !== null
            ? FinancialCalendar::parseMonth($fromMonth, 'from')
            : $end->subMonthsNoOverflow(5)->startOfMonth();

        FinancialCalendar::assertMonthRange($start, $end);

        $schedules = RecurringExpense::query()
            ->whereUser($userId)
            ->with(['category:id,name', 'subscription:id,name,status'])
            ->orderBy('description')
            ->get();

        $subscriptionData = $this->subscriptions->payloadForUser($userId, ['as_of' => $asOf->toDateString()]);
        $subscriptionRows = $subscriptionData['subscriptions'] ?? [];
        $subscriptionSummary = $subscriptionData['summary'] ?? [];

        $activeLinkedIds = [];
        $standaloneMonthlyCents = 0;
        $standaloneAnnualCents = 0;
        $linkedMonthlyCents = 0;
        $linkedAnnualCents = 0;

        foreach ($subscriptionRows as $subscription) {
            if (($subscription['effective_status'] ?? null) !== 'active') {
                continue;
            }

            if (($subscription['is_linked'] ?? false) === true) {
                $activeLinkedIds[(int) ($subscription['recurring_expense_id'] ?? 0)] = true;
                $linkedMonthlyCents += Money::toCents((string) ($subscription['monthly_cost'] ?? '0'));
                $linkedAnnualCents += Money::toCents((string) ($subscription['annual_cost'] ?? '0'));
            } else {
                $standaloneMonthlyCents += Money::toCents((string) ($subscription['monthly_cost'] ?? '0'));
                $standaloneAnnualCents += Money::toCents((string) ($subscription['annual_cost'] ?? '0'));
            }
        }

        $scheduleMonthlyCents = 0;
        $scheduleAnnualCents = 0;
        $activeScheduleCount = 0;
        $scheduleStatusCounts = ['active' => 0, 'paused' => 0, 'stopped' => 0];
        $schedulePayload = [];

        foreach ($schedules as $schedule) {
            $scheduleStatusCounts[$schedule->status] = ($scheduleStatusCounts[$schedule->status] ?? 0) + 1;
            $monthly = FrequencyCost::monthlyCents((string) $schedule->amount, $schedule->frequency);
            $annual = FrequencyCost::annualCents((string) $schedule->amount, $schedule->frequency);

            if ($schedule->status === 'active') {
                $activeScheduleCount++;
                $scheduleMonthlyCents += $monthly;
                $scheduleAnnualCents += $annual;
            }

            $schedulePayload[] = [
                'id' => $schedule->id,
                'description' => $schedule->description,
                'category_name' => $schedule->category?->name,
                'amount' => (string) $schedule->amount,
                'frequency' => $schedule->frequency,
                'frequency_label' => FrequencyCost::frequencyLabel($schedule->frequency),
                'monthly_cost' => Money::fromCents($monthly),
                'annual_cost' => Money::fromCents($annual),
                'status' => $schedule->status,
                'start_date' => $schedule->start_date->format('Y-m-d'),
                'end_date' => $schedule->end_date?->format('Y-m-d'),
                'is_linked' => $schedule->subscription !== null || isset($activeLinkedIds[$schedule->id]),
                'linked_subscription_name' => $schedule->subscription?->name,
                'counts_toward_totals' => $schedule->status === 'active',
            ];
        }

        $upcoming = $this->upcomingObligations($userId, $asOf, $subscriptionRows, $schedules);
        $history = $this->history(
            $schedules,
            $subscriptionRows,
            $start,
            $end,
            $asOf
        );

        $combinedMonthly = $scheduleMonthlyCents + $standaloneMonthlyCents;
        $combinedAnnual = $scheduleAnnualCents + $standaloneAnnualCents;
        $historyFirst = $history[0]['combined_monthly_amount'] ?? '0.00';
        $historyLast = $history[count($history) - 1]['combined_monthly_amount'] ?? '0.00';
        $changeCents = Money::toCents($historyLast) - Money::toCents($historyFirst);

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'as_of' => $asOf->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
            'summary' => [
                'combined_monthly_commitment' => Money::fromCents($combinedMonthly),
                'combined_annual_commitment' => Money::fromCents($combinedAnnual),
                'bill_schedule_monthly_cost' => Money::fromCents($scheduleMonthlyCents),
                'standalone_subscription_monthly_cost' => Money::fromCents($standaloneMonthlyCents),
                'linked_subscription_monthly_cost' => Money::fromCents($linkedMonthlyCents),
                'active_schedule_count' => $activeScheduleCount,
                'active_subscription_count' => (int) ($subscriptionSummary['active_count'] ?? 0),
                'standalone_subscription_count' => (int) ($subscriptionSummary['standalone_count'] ?? 0),
                'linked_subscription_count' => (int) ($subscriptionSummary['linked_count'] ?? 0),
                'schedule_status_counts' => $scheduleStatusCounts,
                'subscription_status_counts' => [
                    'active' => (int) ($subscriptionSummary['active_count'] ?? 0),
                    'paused' => (int) ($subscriptionSummary['paused_count'] ?? 0),
                    'cancelled' => (int) ($subscriptionSummary['cancelled_count'] ?? 0),
                ],
                'upcoming_window_days' => self::UPCOMING_DAYS,
                'upcoming_count' => count($upcoming),
                'upcoming_total_amount' => Money::fromCents(array_sum(array_map(
                    fn (array $row): int => Money::toCents($row['amount']),
                    $upcoming
                ))),
                'history_change_amount' => Money::fromCents($changeCents),
                'history_change_percent' => Money::toCents($historyFirst) > 0
                    ? round(($changeCents / Money::toCents($historyFirst)) * 100, 1)
                    : null,
                'has_commitment_data' => $schedules->isNotEmpty() || $subscriptionRows !== [],
            ],
            'schedules' => $schedulePayload,
            'upcoming_obligations' => $upcoming,
            'history' => $history,
            'subscription_breakdown' => [
                'standalone' => array_values(array_filter(
                    $subscriptionRows,
                    fn (array $row): bool => ($row['is_linked'] ?? false) === false
                )),
                'linked' => array_values(array_filter(
                    $subscriptionRows,
                    fn (array $row): bool => ($row['is_linked'] ?? false) === true
                )),
                'summary' => $subscriptionSummary,
            ],
            'notes' => [
                'A linked subscription derives its amount, frequency and renewal from the recurring bill schedule. Its monthly cost is included in the bill schedule total and excluded from the standalone subscription total.',
                'Expected upcoming bills and subscription renewals are obligations, not paid expenses.',
                'The monthly history is a planning estimate built from current schedule definitions and active standalone subscriptions; historical schedule amounts are not versioned.',
                'Monthly estimates annualize each frequency and divide by twelve, rounded to the nearest cent.',
                'This page cannot cancel a provider subscription or pay a bill.',
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptionRows
     * @param  Collection<int, RecurringExpense>  $schedules
     * @return list<array<string, mixed>>
     */
    private function upcomingObligations(
        int $userId,
        CarbonImmutable $asOf,
        array $subscriptionRows,
        $schedules
    ): array {
        $rows = [];
        $windowStart = $asOf->subDays(90)->toDateString();
        $windowEnd = $asOf->addDays(self::UPCOMING_DAYS)->toDateString();
        $activeScheduleIds = $schedules
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($activeScheduleIds !== []) {
            $occurrences = RecurringExpenseOccurrence::query()
                ->whereIn('recurring_expense_id', $activeScheduleIds)
                ->where('status', 'planned')
                ->whereDate('occurrence_date', '>=', $windowStart)->whereDate('occurrence_date', '<=', $windowEnd)
                ->with(['schedule.category:id,name'])
                ->orderBy('occurrence_date')
                ->get();

            foreach ($occurrences as $occurrence) {
                $date = $occurrence->occurrence_date->toDateString();
                $rows[] = [
                    'source' => 'bill',
                    'source_label' => 'Bill schedule',
                    'id' => $occurrence->id,
                    'name' => $occurrence->schedule?->description ?? 'Bill',
                    'category_name' => $occurrence->schedule?->category?->name,
                    'date' => $date,
                    'amount' => (string) $occurrence->expected_amount,
                    'status' => $date < $asOf->toDateString() ? 'overdue' : 'upcoming',
                    'days_until' => (int) $asOf->diffInDays($occurrence->occurrence_date, false),
                    'recurring_expense_id' => $occurrence->recurring_expense_id,
                    'subscription_id' => null,
                    'counted_once' => true,
                ];
            }
        }

        foreach ($subscriptionRows as $subscription) {
            if (($subscription['is_linked'] ?? false) === true
                || ($subscription['effective_status'] ?? null) !== 'active'
                || ($subscription['next_renewal_date'] ?? null) === null) {
                continue;
            }

            $date = (string) $subscription['next_renewal_date'];

            if ($date < $windowStart || $date > $windowEnd) {
                continue;
            }

            $rows[] = [
                'source' => 'subscription',
                'source_label' => 'Standalone subscription',
                'id' => $subscription['id'],
                'name' => $subscription['name'],
                'category_name' => $subscription['category']['name'] ?? null,
                'date' => $date,
                'amount' => $subscription['amount'],
                'status' => $date < $asOf->toDateString() ? 'overdue' : 'upcoming',
                'days_until' => (int) $asOf->diffInDays(FinancialCalendar::parseDate($date, 'next_renewal_date'), false),
                'recurring_expense_id' => null,
                'subscription_id' => $subscription['id'],
                'counted_once' => true,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);

        return $rows;
    }

    /**
     * @param  Collection<int, RecurringExpense>  $schedules
     * @param  array<int, array<string, mixed>>  $subscriptionRows
     * @return list<array<string, mixed>>
     */
    private function history(
        $schedules,
        array $subscriptionRows,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $asOf
    ): array {
        $months = FinancialCalendar::monthKeys($start, $end->endOfMonth());
        $standalone = array_values(array_filter(
            $subscriptionRows,
            fn (array $row): bool => ($row['is_linked'] ?? false) === false
                && ($row['effective_status'] ?? null) === 'active'
        ));
        $history = [];

        foreach ($months as $month) {
            $monthStart = FinancialCalendar::parseMonth($month, 'month');
            $monthEnd = $monthStart->endOfMonth();
            $scheduleCents = 0;

            foreach ($schedules as $schedule) {
                if ($schedule->status !== 'active'
                    || $schedule->start_date->gt($monthEnd)
                    || ($schedule->end_date !== null && $schedule->end_date->lt($monthStart))) {
                    continue;
                }

                $scheduleCents += FrequencyCost::monthlyCents((string) $schedule->amount, $schedule->frequency);
            }

            $standaloneCents = 0;
            foreach ($standalone as $subscription) {
                $createdAt = isset($subscription['created_at'])
                    ? FinancialCalendar::parseDate(substr((string) $subscription['created_at'], 0, 10), 'created_at')
                    : null;

                if ($createdAt !== null && $createdAt->gt($monthEnd)) {
                    continue;
                }

                $standaloneCents += Money::toCents((string) ($subscription['monthly_cost'] ?? '0'));
            }

            $history[] = [
                'month' => $month,
                'bill_schedule_monthly_amount' => Money::fromCents($scheduleCents),
                'standalone_subscription_monthly_amount' => Money::fromCents($standaloneCents),
                'combined_monthly_amount' => Money::fromCents($scheduleCents + $standaloneCents),
                'is_planning_estimate' => true,
            ];
        }

        return $history;
    }
}
