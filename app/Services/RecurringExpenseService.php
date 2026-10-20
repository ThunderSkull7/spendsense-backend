<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\RecurringExpenseOccurrence;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecurringExpenseService
{
    public const MAX_OCCURRENCES = RecurrenceDateService::MAX_OCCURRENCES;

    public function __construct(
        private readonly RecurrenceDateService $dates,
        private readonly BillReminderService $reminders
    ) {}

    /**
     * @return array{dates: array<int, string>, count: int, total_expected: string, start_date: string, end_date: string, open_ended: bool, max_occurrences: int}
     */
    public function preview(string $frequency, string $amount, string $startDate, ?string $endDate): array
    {
        $openEnded = $endDate === null || $endDate === '';
        $generationStart = $this->dates->generationStartDate($frequency, $startDate, $endDate);
        $generationEnd = $this->dates->generationEndDate($frequency, $startDate, $endDate);
        $dates = $this->dates->occurrenceDates($frequency, $generationStart, $generationEnd);

        return [
            'dates' => $dates,
            'count' => count($dates),
            'total_expected' => $this->fromCents($this->toCents($amount) * count($dates)),
            'start_date' => $generationStart,
            'end_date' => $generationEnd,
            'open_ended' => $openEnded,
            'max_occurrences' => self::MAX_OCCURRENCES,
        ];
    }

    public function generateInitial(RecurringExpense $schedule): int
    {
        $generationStart = $this->dates->generationStartDate(
            $schedule->frequency,
            $schedule->start_date->toDateString(),
            $schedule->end_date?->toDateString()
        );
        $generationEnd = $this->dates->generationEndDate(
            $schedule->frequency,
            $schedule->start_date->toDateString(),
            $schedule->end_date?->toDateString()
        );

        $dates = $this->dates->occurrenceDates($schedule->frequency, $generationStart, $generationEnd);

        return $this->insertDates($schedule, $dates);
    }

    /**
     * Rebuild only unreconciled future expectations. Paid, skipped, cancelled
     * and historical planned occurrences are preserved, so editing a schedule
     * never rewrites actual financial records.
     */
    public function regenerateFuture(RecurringExpense $schedule): int
    {
        $today = $this->dates->today();

        $schedule->occurrences()
            ->where('status', 'planned')
            ->whereDate('occurrence_date', '>=', $today)
            ->delete();

        $generationStart = $this->dates->generationStartDate(
            $schedule->frequency,
            $schedule->start_date->toDateString(),
            $schedule->end_date?->toDateString()
        );
        $generationEnd = $this->dates->generationEndDate(
            $schedule->frequency,
            $schedule->start_date->toDateString(),
            $schedule->end_date?->toDateString()
        );

        if ($generationStart > $generationEnd) {
            return 0;
        }

        $dates = array_values(array_filter(
            $this->dates->occurrenceDates($schedule->frequency, $generationStart, $generationEnd),
            fn (string $date) => $date >= $today
        ));

        return $this->insertDates($schedule, $dates);
    }

    public function schedulePayload(RecurringExpense $schedule, bool $withOccurrences = false): array
    {
        $schedule->loadMissing(['category', 'occurrences.expense']);
        $today = CarbonImmutable::today('UTC');

        $occurrences = $schedule->occurrences
            ->map(fn (RecurringExpenseOccurrence $occurrence) => $this->occurrencePayload($occurrence, $today))
            ->values();

        $summary = $this->summary($schedule, $today);
        $nextDue = $occurrences
            ->first(fn (array $occurrence) => $occurrence['status'] === 'planned' && $occurrence['occurrence_date'] >= $today->toDateString());

        $payload = [
            'id' => $schedule->id,
            'description' => $schedule->description,
            'amount' => $schedule->amount,
            'category_id' => $schedule->category_id,
            'category' => $schedule->category === null ? null : [
                'id' => $schedule->category->id,
                'name' => $schedule->category->name,
                'type' => $schedule->category->type,
            ],
            'frequency' => $schedule->frequency,
            'start_date' => $schedule->start_date->toDateString(),
            'end_date' => $schedule->end_date?->toDateString(),
            'status' => $schedule->status,
            'reminder_days_before' => $schedule->reminder_days_before,
            'notes' => $schedule->notes,
            'next_due_date' => $schedule->status === 'stopped' ? null : $nextDue['occurrence_date'] ?? null,
            'occurrence_count' => $occurrences->count(),
            'summary' => $summary,
            'expectation_only' => true,
        ];

        if ($withOccurrences) {
            $payload['occurrences'] = $occurrences->all();
        }

        return $payload;
    }

    public function occurrencePayload(RecurringExpenseOccurrence $occurrence, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $occurrence->loadMissing(['schedule.category', 'expense']);

        $schedule = $occurrence->schedule;
        $category = $schedule?->category;
        $expense = $occurrence->expense;
        $date = $occurrence->occurrence_date->toDateString();
        $displayStatus = $this->displayStatus($occurrence, $today);

        return [
            'id' => $occurrence->id,
            'occurrence_date' => $date,
            'expected_amount' => $occurrence->expected_amount,
            'status' => $occurrence->status,
            'display_status' => $displayStatus,
            'is_overdue' => $displayStatus === 'overdue',
            'is_due' => $displayStatus === 'due',
            'expense_id' => $occurrence->expense_id,
            'actual_amount' => $expense?->amount,
            'payment_date' => $expense?->expense_date?->toDateString(),
            'paid_at' => $occurrence->paid_at?->toIso8601String(),
            'expense_deleted' => $expense !== null && $expense->trashed(),
            'can_pay' => $occurrence->status === 'planned',
            'can_skip' => $occurrence->status === 'planned',
            'can_mark_unpaid' => $occurrence->status === 'skipped',
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->id,
                'description' => $schedule->description,
                'status' => $schedule->status,
                'frequency' => $schedule->frequency,
                'reminder_days_before' => $schedule->reminder_days_before,
                'category' => $category === null ? null : [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<string, int|string>
     */
    public function aggregate(array $payloads): array
    {
        $counts = ['active' => 0, 'paused' => 0, 'stopped' => 0];
        $unpaid = 0;
        $overdue = 0;

        foreach ($payloads as $payload) {
            $status = $payload['status'] ?? 'active';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $summary = $payload['summary'] ?? [];
            $unpaid += $this->toCents($summary['unpaid_total'] ?? 0);
            $overdue += $this->toCents($summary['overdue_total'] ?? 0);
        }

        return [
            'active_count' => $counts['active'],
            'paused_count' => $counts['paused'],
            'stopped_count' => $counts['stopped'],
            'unpaid_total' => $this->fromCents($unpaid),
            'overdue_total' => $this->fromCents($overdue),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(RecurringExpense $schedule, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $schedule->loadMissing('occurrences.expense');

        $expectedCents = 0;
        $paidCents = 0;
        $unpaidCents = 0;
        $overdueCents = 0;
        $skippedCents = 0;
        $counts = [
            'planned' => 0,
            'paid' => 0,
            'skipped' => 0,
            'cancelled' => 0,
            'overdue' => 0,
        ];

        foreach ($schedule->occurrences as $occurrence) {
            $expected = $this->toCents($occurrence->expected_amount);
            $date = $occurrence->occurrence_date->toDateString();
            $counts[$occurrence->status] = ($counts[$occurrence->status] ?? 0) + 1;

            if (in_array($occurrence->status, ['planned', 'paid'], true)) {
                $expectedCents += $expected;
            }

            if ($occurrence->status === 'planned') {
                $unpaidCents += $expected;
                if ($date < $today->toDateString()) {
                    $overdueCents += $expected;
                    $counts['overdue']++;
                }
            }

            if ($occurrence->status === 'paid') {
                $paidCents += $occurrence->expense === null
                    ? $expected
                    : $this->toCents($occurrence->expense->amount);
            }

            if ($occurrence->status === 'skipped') {
                $skippedCents += $expected;
            }
        }

        return [
            'expected_total' => $this->fromCents($expectedCents),
            'paid_total' => $this->fromCents($paidCents),
            'unpaid_total' => $this->fromCents($unpaidCents),
            'overdue_total' => $this->fromCents($overdueCents),
            'skipped_total' => $this->fromCents($skippedCents),
            'planned_count' => $counts['planned'],
            'paid_count' => $counts['paid'],
            'skipped_count' => $counts['skipped'],
            'cancelled_count' => $counts['cancelled'],
            'overdue_count' => $counts['overdue'],
        ];
    }

    /**
     * Create exactly one actual expense for an expected occurrence. The
     * schedule row lock serializes repeated and concurrent payment attempts;
     * the unique expense link and paid status are the final guards.
     */
    public function pay(
        RecurringExpense $schedule,
        RecurringExpenseOccurrence $occurrence,
        int $userId,
        ?string $actualAmount = null,
        ?string $paidDate = null
    ): RecurringExpenseOccurrence {
        return DB::transaction(function () use ($schedule, $occurrence, $userId, $actualAmount, $paidDate) {
            $lockedSchedule = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOccurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOccurrence->status === 'paid') {
                throw new ConflictHttpException('This occurrence has already been paid.');
            }

            if ($lockedOccurrence->status === 'skipped') {
                throw new ConflictHttpException('Mark this skipped occurrence unpaid before recording a payment.');
            }

            if ($lockedOccurrence->status === 'cancelled') {
                throw new ConflictHttpException('A cancelled occurrence cannot be paid.');
            }

            $category = Category::query()->find($lockedSchedule->category_id);
            if ($category === null || $category->type !== 'expense') {
                throw ValidationException::withMessages([
                    'category_id' => 'The schedule category is no longer available for expenses.',
                ]);
            }

            $amount = $actualAmount === null || $actualAmount === ''
                ? (string) $lockedOccurrence->expected_amount
                : (string) $actualAmount;

            if ($this->toCents($amount) <= 0) {
                throw ValidationException::withMessages([
                    'actual_amount' => 'The payment amount must be greater than zero.',
                ]);
            }

            $paymentDate = $paidDate === null || $paidDate === ''
                ? $lockedOccurrence->occurrence_date->toDateString()
                : $this->normalizeDate($paidDate, 'paid_date');

            $expense = Expense::create([
                'user_id' => $userId,
                'category_id' => $lockedSchedule->category_id,
                'amount' => $this->fromCents($this->toCents($amount)),
                'description' => $lockedSchedule->description,
                'expense_date' => $paymentDate,
            ]);

            $lockedOccurrence->update([
                'status' => 'paid',
                'expense_id' => $expense->id,
                'paid_at' => now(),
            ]);

            $this->reminders->deleteForOccurrence($lockedOccurrence->id);

            return $lockedOccurrence->fresh(['expense', 'schedule.category']);
        });
    }

    public function skip(
        RecurringExpense $schedule,
        RecurringExpenseOccurrence $occurrence,
        int $userId
    ): RecurringExpenseOccurrence {
        return DB::transaction(function () use ($schedule, $occurrence, $userId) {
            $lockedSchedule = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOccurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOccurrence->status === 'paid') {
                throw new ConflictHttpException('A paid occurrence cannot be skipped.');
            }

            if ($lockedOccurrence->status === 'cancelled') {
                throw new ConflictHttpException('A cancelled occurrence cannot be skipped.');
            }

            if ($lockedOccurrence->status === 'planned') {
                $lockedOccurrence->update(['status' => 'skipped']);
                $this->reminders->deleteForOccurrence($lockedOccurrence->id);
            }

            return $lockedOccurrence->fresh(['expense', 'schedule.category']);
        });
    }

    public function markUnpaid(
        RecurringExpense $schedule,
        RecurringExpenseOccurrence $occurrence,
        int $userId
    ): RecurringExpenseOccurrence {
        return DB::transaction(function () use ($schedule, $occurrence, $userId) {
            $lockedSchedule = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedOccurrence = $lockedSchedule->occurrences()
                ->whereKey($occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOccurrence->status === 'paid') {
                throw new ConflictHttpException(
                    'A paid occurrence is reversed only by reviewing its linked expense in the expense trash/restore flow.'
                );
            }

            if ($lockedOccurrence->status === 'cancelled') {
                throw new ConflictHttpException('A cancelled occurrence cannot be marked unpaid.');
            }

            if ($lockedOccurrence->status === 'skipped') {
                $lockedOccurrence->update(['status' => 'planned']);
                $this->reminders->deleteForOccurrence($lockedOccurrence->id);
            }

            return $lockedOccurrence->fresh(['expense', 'schedule.category']);
        });
    }

    public function pause(RecurringExpense $schedule, int $userId): RecurringExpense
    {
        return DB::transaction(function () use ($schedule, $userId) {
            $locked = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be paused.');
            }

            if ($locked->status !== 'paused') {
                $locked->update(['status' => 'paused']);
                $this->reminders->deleteForSchedule($locked->id);
            }

            return $locked->fresh(['category', 'occurrences.expense']);
        });
    }

    public function resume(RecurringExpense $schedule, int $userId): RecurringExpense
    {
        return DB::transaction(function () use ($schedule, $userId) {
            $locked = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be resumed.');
            }

            if ($locked->status !== 'active') {
                $locked->update(['status' => 'active']);
            }

            return $locked->fresh(['category', 'occurrences.expense']);
        });
    }

    public function stop(RecurringExpense $schedule, int $userId): RecurringExpense
    {
        return DB::transaction(function () use ($schedule, $userId) {
            $locked = RecurringExpense::query()
                ->where('user_id', $userId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'stopped') {
                $today = $this->dates->today();
                $locked->update(['status' => 'stopped']);
                $locked->occurrences()
                    ->where('status', 'planned')
                    ->whereDate('occurrence_date', '>=', $today)
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                $this->reminders->deleteForSchedule($locked->id);
            }

            return $locked->fresh(['category', 'occurrences.expense']);
        });
    }

    public function displayStatus(RecurringExpenseOccurrence $occurrence, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today('UTC');

        if ($occurrence->status !== 'planned') {
            return $occurrence->status;
        }

        $date = $occurrence->occurrence_date->toDateString();
        if ($date < $today->toDateString()) {
            return 'overdue';
        }

        if ($date === $today->toDateString()) {
            return 'due';
        }

        return 'upcoming';
    }

    public function toCents(string|int|float $amount): int
    {
        return Money::toCents($amount);
    }

    public function fromCents(int $cents): string
    {
        return Money::fromCents($cents);
    }

    private function insertDates(RecurringExpense $schedule, array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($dates as $date) {
            $rows[] = [
                'recurring_expense_id' => $schedule->id,
                'occurrence_date' => $date,
                'expected_amount' => $schedule->amount,
                'status' => 'planned',
                'expense_id' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $inserted += RecurringExpenseOccurrence::insertOrIgnore($chunk);
        }

        return $inserted;
    }

    private function normalizeDate(string $value, string $field): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => 'Use a valid date in YYYY-MM-DD format.',
            ]);
        }

        return $date->toDateString();
    }
}
