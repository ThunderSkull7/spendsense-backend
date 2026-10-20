<?php

namespace App\Services;

use App\Models\Income;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecurringIncomeService
{
    /**
     * A finite, reviewable horizon. The API returns a validation error rather
     * than silently truncating a schedule, and the same limit is shown in the
     * form preview. A future scheduler can extend a schedule in bounded runs.
     */
    public const MAX_OCCURRENCES = RecurrenceDateService::MAX_OCCURRENCES;

    public function __construct(private readonly RecurrenceDateService $dates) {}

    /**
     * Calendar dates are calculated in UTC from the original start date. The
     * values are date-only, so daylight-saving transitions cannot add or remove
     * a day, and weekly schedules stay exactly seven calendar days apart.
     *
     * @return array<int, string> YYYY-MM-DD dates
     */
    public function occurrenceDates(string $frequency, string $startDate, string $endDate): array
    {
        return $this->dates->occurrenceDates($frequency, $startDate, $endDate);
    }

    /**
     * @return array{dates: array<int, string>, count: int, total_expected: string}
     */
    public function preview(string $frequency, string $amount, string $startDate, string $endDate): array
    {
        $dates = $this->occurrenceDates($frequency, $startDate, $endDate);

        return [
            'dates' => $dates,
            'count' => count($dates),
            'total_expected' => $this->fromCents($this->toCents($amount) * count($dates)),
        ];
    }

    /**
     * Generate the full bounded schedule. insertOrIgnore relies on the unique
     * (schedule, occurrence_date) index, so reruns and concurrent generators
     * cannot create duplicates.
     */
    public function generateInitial(RecurringIncome $schedule): int
    {
        $dates = $this->occurrenceDates(
            $schedule->frequency,
            $schedule->start_date->toDateString(),
            $schedule->end_date->toDateString()
        );

        return $this->insertDates($schedule, $dates);
    }

    /**
     * Rebuild only unreceived, not-yet-past occurrences. Received rows and
     * historical planned rows are deliberately left untouched.
     */
    public function regenerateFuture(RecurringIncome $schedule): int
    {
        $today = $this->today();

        $schedule->occurrences()
            ->where('status', 'planned')
            ->whereDate('occurrence_date', '>=', $today)
            ->delete();

        $dates = array_values(array_filter(
            $this->occurrenceDates(
                $schedule->frequency,
                $schedule->start_date->toDateString(),
                $schedule->end_date->toDateString()
            ),
            fn (string $date) => $date >= $today
        ));

        return $this->insertDates($schedule, $dates);
    }

    /**
     * Record one occurrence as received. The actual income row and the
     * occurrence update happen in one transaction and are serialized with a
     * row lock. A second request sees status=received and gets 409.
     */
    public function receive(
        RecurringIncome $schedule,
        int $occurrenceId,
        ?string $actualAmount = null,
        ?string $actualReceivedDate = null
    ): RecurringIncomeOccurrence {
        return DB::transaction(function () use ($schedule, $occurrenceId, $actualAmount, $actualReceivedDate) {
            $lockedSchedule = RecurringIncome::query()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $occurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrenceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($occurrence->status === 'received') {
                throw new ConflictHttpException('This occurrence has already been received.');
            }
            if ($occurrence->status === 'cancelled') {
                throw new ConflictHttpException('A cancelled occurrence cannot be received.');
            }

            $amount = $actualAmount ?? $occurrence->expected_amount;
            $receivedDate = $actualReceivedDate ?? $occurrence->occurrence_date->toDateString();

            $income = Income::create([
                'user_id' => $lockedSchedule->user_id,
                'amount' => $amount,
                'source' => $lockedSchedule->name,
                'income_date' => $receivedDate,
                'frequency' => 'one-time',
                'notes' => 'Received from recurring income schedule: '.$lockedSchedule->name,
            ]);

            $occurrence->update([
                'status' => 'received',
                'income_id' => $income->id,
            ]);

            return $occurrence->fresh('income');
        });
    }

    /**
     * Correct the linked actual income. The occurrence's expected amount is
     * immutable; only the real income row changes.
     */
    public function correctReceipt(
        RecurringIncome $schedule,
        int $occurrenceId,
        string $actualAmount,
        string $actualReceivedDate
    ): RecurringIncomeOccurrence {
        return DB::transaction(function () use ($schedule, $occurrenceId, $actualAmount, $actualReceivedDate) {
            $lockedSchedule = RecurringIncome::query()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $occurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrenceId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($occurrence->status !== 'received' || ! $occurrence->income_id) {
                throw new ConflictHttpException('Only a received occurrence can be corrected.');
            }

            $income = Income::query()
                ->whereKey($occurrence->income_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($income->user_id !== $lockedSchedule->user_id) {
                throw new ConflictHttpException('The linked income does not belong to this schedule.');
            }

            $income->update([
                'amount' => $actualAmount,
                'income_date' => $actualReceivedDate,
            ]);

            return $occurrence->fresh('income');
        });
    }

    public function pause(RecurringIncome $schedule): RecurringIncome
    {
        return DB::transaction(function () use ($schedule) {
            $locked = RecurringIncome::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be paused.');
            }
            if ($locked->status !== 'paused') {
                $locked->update(['status' => 'paused']);
            }

            return $locked;
        });
    }

    public function resume(RecurringIncome $schedule): RecurringIncome
    {
        return DB::transaction(function () use ($schedule) {
            $locked = RecurringIncome::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be resumed.');
            }
            if ($locked->status !== 'active') {
                $locked->update(['status' => 'active']);
            }

            return $locked;
        });
    }

    /**
     * Stop is terminal. Future planned occurrences become cancelled; past
     * planned occurrences stay visible as missed history, and received rows
     * are never deleted.
     */
    public function stop(RecurringIncome $schedule): RecurringIncome
    {
        return DB::transaction(function () use ($schedule) {
            $locked = RecurringIncome::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'stopped') {
                return $locked;
            }

            $locked->update(['status' => 'stopped']);

            $locked->occurrences()
                ->where('status', 'planned')
                ->whereDate('occurrence_date', '>=', $this->today())
                ->update(['status' => 'cancelled']);

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulePayload(RecurringIncome $schedule, bool $includeOccurrences = false): array
    {
        $occurrences = $schedule->relationLoaded('occurrences')
            ? $schedule->occurrences
            : $schedule->occurrences()->with('income')->get();

        if (! $schedule->relationLoaded('occurrences')) {
            $schedule->setRelation('occurrences', $occurrences);
        }

        $today = $this->today();
        $expectedCents = 0;
        $receivedCents = 0;
        $overdueCents = 0;
        $upcomingCents = 0;
        $receivedCount = 0;
        $overdueCount = 0;
        $upcomingCount = 0;
        $cancelledCount = 0;
        $nextDueDate = null;

        foreach ($occurrences as $occurrence) {
            $date = $occurrence->occurrence_date->toDateString();

            if ($occurrence->status === 'cancelled') {
                $cancelledCount++;

                continue;
            }

            $expectedCents += $this->toCents($occurrence->expected_amount);

            if ($occurrence->status === 'received') {
                $receivedCount++;
                $receivedCents += $this->toCents($occurrence->income?->amount ?? '0.00');

                continue;
            }

            if ($date < $today) {
                $overdueCount++;
                $overdueCents += $this->toCents($occurrence->expected_amount);

                continue;
            }

            $upcomingCount++;
            $upcomingCents += $this->toCents($occurrence->expected_amount);
            if ($nextDueDate === null) {
                $nextDueDate = $date;
            }
        }

        $payload = [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'amount' => $schedule->amount,
            'frequency' => $schedule->frequency,
            'start_date' => $schedule->start_date->toDateString(),
            'end_date' => $schedule->end_date->toDateString(),
            'status' => $schedule->status,
            'notes' => $schedule->notes,
            'next_due_date' => $nextDueDate,
            'summary' => [
                'expected_total' => $this->fromCents($expectedCents),
                'received_total' => $this->fromCents($receivedCents),
                'overdue_total' => $this->fromCents($overdueCents),
                'upcoming_total' => $this->fromCents($upcomingCents),
                'received_count' => $receivedCount,
                'overdue_count' => $overdueCount,
                'upcoming_count' => $upcomingCount,
                'cancelled_count' => $cancelledCount,
            ],
        ];

        if ($includeOccurrences) {
            $payload['occurrences'] = $occurrences
                ->map(fn (RecurringIncomeOccurrence $occurrence) => $this->occurrencePayload($occurrence))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $schedules
     * @return array<string, mixed>
     */
    public function aggregate(array $schedules): array
    {
        $expected = 0;
        $received = 0;
        $overdue = 0;
        $upcoming = 0;
        $active = 0;
        $paused = 0;
        $stopped = 0;
        $overdueCount = 0;

        foreach ($schedules as $schedule) {
            $expected += $this->toCents($schedule['summary']['expected_total']);
            $received += $this->toCents($schedule['summary']['received_total']);
            $overdue += $this->toCents($schedule['summary']['overdue_total']);
            $upcoming += $this->toCents($schedule['summary']['upcoming_total']);
            $overdueCount += $schedule['summary']['overdue_count'];

            if ($schedule['status'] === 'active') {
                $active++;
            } elseif ($schedule['status'] === 'paused') {
                $paused++;
            } else {
                $stopped++;
            }
        }

        return [
            'expected_total' => $this->fromCents($expected),
            'received_total' => $this->fromCents($received),
            'overdue_total' => $this->fromCents($overdue),
            'upcoming_total' => $this->fromCents($upcoming),
            'overdue_count' => $overdueCount,
            'active_count' => $active,
            'paused_count' => $paused,
            'stopped_count' => $stopped,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function occurrencePayload(RecurringIncomeOccurrence $occurrence): array
    {
        $income = $occurrence->income;

        return [
            'id' => $occurrence->id,
            'occurrence_date' => $occurrence->occurrence_date->toDateString(),
            'expected_amount' => $occurrence->expected_amount,
            'status' => $occurrence->status,
            'display_status' => $occurrence->displayStatus(),
            'income_id' => $occurrence->income_id,
            'actual_amount' => $income?->amount,
            'actual_received_date' => $income?->income_date?->toDateString(),
        ];
    }

    /**
     * @param  array<int, string>  $dates
     */
    private function insertDates(RecurringIncome $schedule, array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($dates as $date) {
            $rows[] = [
                'recurring_income_id' => $schedule->id,
                'occurrence_date' => $date,
                'expected_amount' => $schedule->amount,
                'status' => 'planned',
                'income_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $inserted += RecurringIncomeOccurrence::insertOrIgnore($chunk);
        }

        return $inserted;
    }

    private function today(): string
    {
        return $this->dates->today();
    }

    public function toCents(string|int|float $amount): int
    {
        return Money::toCents($amount);
    }

    public function fromCents(int $cents): string
    {
        return Money::fromCents($cents);
    }
}
