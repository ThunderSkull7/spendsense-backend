<?php

namespace App\Services;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;

class SavingsProgressService
{
    /**
     * Savings contributions are internal tracking entries, not income or
     * expenses. Archived goals remain visible in the detailed list but never
     * contribute to active totals.
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

        $endDate = $end->endOfMonth()->toDateString();
        $startDate = $start->toDateString();

        $goals = SavingsGoal::query()
            ->whereUser($userId)
            ->with(['contributions' => fn ($query) => $query
                ->whereDate('contributed_at', '<=', $endDate)
                ->orderBy('contributed_at')
                ->orderBy('id')])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();

        $months = FinancialCalendar::monthKeys($start, $end->endOfMonth());
        $monthly = [];

        foreach ($months as $month) {
            $monthly[$month] = [
                'month' => $month,
                'contributions_cents' => 0,
                'corrections_cents' => 0,
            ];
        }

        foreach ($goals as $goal) {
            if ($goal->status === SavingsGoal::STATUS_ARCHIVED) {
                continue;
            }

            foreach ($goal->contributions as $contribution) {
                $month = $contribution->contributed_at->format('Y-m');
                if (! isset($monthly[$month])) {
                    continue;
                }

                if ($contribution->type === SavingsGoalContribution::TYPE_CORRECTION) {
                    $monthly[$month]['corrections_cents'] += Money::toCents((string) $contribution->amount);
                } else {
                    $monthly[$month]['contributions_cents'] += Money::toCents((string) $contribution->amount);
                }
            }
        }

        // Opening balance is the net of all contributions before the selected
        // period, so the selected-period chart has a meaningful cumulative line.
        $openingCents = 0;
        foreach ($goals as $goal) {
            if ($goal->status === SavingsGoal::STATUS_ARCHIVED) {
                continue;
            }

            foreach ($goal->contributions as $contribution) {
                if ($contribution->contributed_at->lt($start)) {
                    $openingCents += $this->signedCents($contribution);
                }
            }
        }

        $runningCents = $openingCents;
        $history = [];

        foreach ($monthly as $month => $row) {
            $net = $row['contributions_cents'] - $row['corrections_cents'];
            $runningCents += $net;
            $history[] = [
                'month' => $month,
                'contributions_amount' => Money::fromCents($row['contributions_cents']),
                'corrections_amount' => Money::fromCents($row['corrections_cents']),
                'net_amount' => Money::fromCents($net),
                'cumulative_active_saved' => Money::fromCents($runningCents),
            ];
        }

        $items = [];
        $summary = [
            'active_goal_count' => 0,
            'completed_goal_count' => 0,
            'archived_goal_count' => 0,
            'total_target_cents' => 0,
            'total_saved_active_cents' => 0,
            'total_saved_completed_cents' => 0,
            'period_net_cents' => 0,
            'on_track_count' => 0,
            'behind_count' => 0,
            'no_estimate_count' => 0,
        ];

        foreach ($goals as $goal) {
            $balanceCents = 0;
            $periodCents = 0;

            foreach ($goal->contributions as $contribution) {
                $signed = $this->signedCents($contribution);
                $balanceCents += $signed;

                if ($contribution->contributed_at->gte($start)) {
                    $periodCents += $signed;
                }
            }

            $targetCents = Money::toCents((string) $goal->target_amount);
            $remainingCents = max(0, $targetCents - $balanceCents);
            $progress = $targetCents > 0 ? round(($balanceCents / $targetCents) * 100, 1) : null;
            $estimate = $this->completionEstimate($goal, $balanceCents, $end);

            if ($goal->status === SavingsGoal::STATUS_ACTIVE) {
                $summary['active_goal_count']++;
                $summary['total_target_cents'] += $targetCents;
                $summary['total_saved_active_cents'] += $balanceCents;
                $summary['period_net_cents'] += $periodCents;

                if ($estimate['status'] === 'estimated') {
                    $summary[$estimate['on_track'] === false ? 'behind_count' : 'on_track_count']++;
                } else {
                    $summary['no_estimate_count']++;
                }
            } elseif ($goal->status === SavingsGoal::STATUS_COMPLETED) {
                $summary['completed_goal_count']++;
                $summary['total_saved_completed_cents'] += $balanceCents;
            } else {
                $summary['archived_goal_count']++;
            }

            $items[] = [
                'id' => $goal->id,
                'name' => $goal->name,
                'status' => $goal->status,
                'target_amount' => Money::fromCents($targetCents),
                'saved_amount' => Money::fromCents($balanceCents),
                'remaining_amount' => Money::fromCents($remainingCents),
                'progress_percent' => $progress,
                'target_date' => $goal->target_date?->format('Y-m-d'),
                'period_net_amount' => Money::fromCents($periodCents),
                'last_contribution_date' => $goal->contributions->last()?->contributed_at->format('Y-m-d'),
                'contribution_count' => $goal->contributions->count(),
                'estimated_completion' => $estimate,
            ];
        }

        usort($items, function (array $a, array $b): int {
            $statusOrder = ['active' => 0, 'completed' => 1, 'archived' => 2];

            return [$statusOrder[$a['status']] ?? 3, $a['target_date'] ?? '9999-12-31']
                <=> [$statusOrder[$b['status']] ?? 3, $b['target_date'] ?? '9999-12-31'];
        });

        $activeTarget = $summary['total_target_cents'];
        $activeSaved = $summary['total_saved_active_cents'];

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'timezone' => FinancialCalendar::TIMEZONE,
            'summary' => [
                'active_goal_count' => $summary['active_goal_count'],
                'completed_goal_count' => $summary['completed_goal_count'],
                'archived_goal_count' => $summary['archived_goal_count'],
                'total_target_amount' => Money::fromCents($activeTarget),
                'total_saved_active_amount' => Money::fromCents($activeSaved),
                'total_remaining_active_amount' => Money::fromCents(max(0, $activeTarget - $activeSaved)),
                'total_saved_completed_amount' => Money::fromCents($summary['total_saved_completed_cents']),
                'period_net_amount' => Money::fromCents($summary['period_net_cents']),
                'overall_progress_percent' => $activeTarget > 0
                    ? round(($activeSaved / $activeTarget) * 100, 1)
                    : null,
                'on_track_count' => $summary['on_track_count'],
                'behind_count' => $summary['behind_count'],
                'no_estimate_count' => $summary['no_estimate_count'],
                'has_goal_data' => $items !== [],
            ],
            'goals' => $items,
            'history' => $history,
            'notes' => [
                'Savings contributions are internal tracking entries and are not income or expenses.',
                'Archived goals remain in the list for history but are excluded from active target and saved totals.',
                'Completion estimates use a simple average of each goal\'s net contributions over at most the last six months. They are planning context, not a promise.',
                'Corrections reduce the tracked saved balance; original contribution entries remain in history.',
            ],
        ];
    }

    private function signedCents(SavingsGoalContribution $contribution): int
    {
        $cents = Money::toCents((string) $contribution->amount);

        return $contribution->type === SavingsGoalContribution::TYPE_CORRECTION ? -$cents : $cents;
    }

    /**
     * @return array<string, mixed>
     */
    private function completionEstimate(SavingsGoal $goal, int $balanceCents, CarbonImmutable $end): array
    {
        $targetCents = Money::toCents((string) $goal->target_amount);

        if ($balanceCents >= $targetCents) {
            return [
                'status' => 'reached',
                'projected_month' => null,
                'months_remaining' => 0,
                'average_monthly_net_amount' => null,
                'on_track' => null,
                'reason' => 'The tracked balance has reached the target.',
            ];
        }

        if ($goal->status !== SavingsGoal::STATUS_ACTIVE) {
            return [
                'status' => 'not_applicable',
                'projected_month' => null,
                'months_remaining' => null,
                'average_monthly_net_amount' => null,
                'on_track' => null,
                'reason' => 'Completion estimates are shown for active goals only.',
            ];
        }

        $since = $end->subMonthsNoOverflow(5)->startOfMonth();
        $netByMonth = [];

        foreach ($goal->contributions as $contribution) {
            if ($contribution->contributed_at->lt($since) || $contribution->contributed_at->gt($end->endOfMonth())) {
                continue;
            }

            $month = $contribution->contributed_at->format('Y-m');
            $netByMonth[$month] = ($netByMonth[$month] ?? 0) + $this->signedCents($contribution);
        }

        $observedMonths = count($netByMonth);

        if ($observedMonths === 0) {
            return [
                'status' => 'insufficient_data',
                'projected_month' => null,
                'months_remaining' => null,
                'average_monthly_net_amount' => null,
                'on_track' => null,
                'reason' => 'No contributions were recorded in the last six months.',
            ];
        }

        $averageCents = intdiv(array_sum($netByMonth), $observedMonths);

        if ($averageCents <= 0) {
            return [
                'status' => 'insufficient_data',
                'projected_month' => null,
                'months_remaining' => null,
                'average_monthly_net_amount' => Money::fromCents($averageCents),
                'on_track' => null,
                'reason' => 'Recent net contributions are zero or negative, so a completion month cannot be estimated.',
            ];
        }

        $remainingCents = $targetCents - $balanceCents;
        $monthsRemaining = (int) ceil($remainingCents / $averageCents);
        $projected = $end->addMonthsNoOverflow($monthsRemaining);
        $onTrack = $goal->target_date !== null
            ? $projected->lte($goal->target_date)
            : null;

        return [
            'status' => 'estimated',
            'projected_month' => $projected->format('Y-m'),
            'months_remaining' => $monthsRemaining,
            'average_monthly_net_amount' => Money::fromCents($averageCents),
            'observed_months' => $observedMonths,
            'on_track' => $onTrack,
            'reason' => $onTrack === false
                ? 'At the recent average, the projected month is after the target date.'
                : 'Estimated from recent net contributions; this is planning context, not a guarantee.',
        ];
    }
}
