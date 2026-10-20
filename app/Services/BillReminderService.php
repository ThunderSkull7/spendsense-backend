<?php

namespace App\Services;

use App\Models\BillReminder;
use App\Models\RecurringExpenseOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BillReminderService
{
    public const TYPE_UPCOMING = 'upcoming';

    public const TYPE_DUE = 'due';

    public const TYPE_OVERDUE = 'overdue';

    /**
     * Reconcile and return the authenticated user's in-app reminders. Reading
     * this endpoint also runs a safe, idempotent sync, so the notification
     * centre still works if the scheduler has not run yet.
     */
    public function payloadForUser(int $userId): array
    {
        $this->syncForUser($userId);

        $today = CarbonImmutable::today('UTC');
        $reminders = BillReminder::query()
            ->whereUser($userId)
            ->with(['occurrence.schedule.category', 'occurrence.expense'])
            ->whereHas('occurrence', function ($query) {
                $query->where('status', 'planned')
                    ->whereHas('schedule', fn ($schedule) => $schedule->where('status', 'active'));
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $typeOrder = [self::TYPE_OVERDUE => 0, self::TYPE_DUE => 1, self::TYPE_UPCOMING => 2];
        $reminders = $reminders
            ->sortBy(fn (BillReminder $reminder) => [
                $typeOrder[$reminder->type] ?? 3,
                $reminder->due_date->toDateString(),
                $reminder->id,
            ])
            ->values();

        return [
            'reminders' => $reminders
                ->map(fn (BillReminder $reminder) => $this->payload($reminder, $today))
                ->all(),
            'summary' => [
                'unread_count' => $reminders->whereNull('read_at')->count(),
                'overdue_count' => $reminders->where('type', self::TYPE_OVERDUE)->count(),
                'due_count' => $reminders->where('type', self::TYPE_DUE)->count(),
                'upcoming_count' => $reminders->where('type', self::TYPE_UPCOMING)->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Insert at most one reminder per occurrence, type and reminder window.
     * The unique database key is the final duplicate guard for concurrent
     * scheduler runs and page requests.
     */
    public function syncForUser(int $userId, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today('UTC');

        // Remove reminders that no longer describe a live expectation. This
        // covers paid/skipped/cancelled occurrences and paused/stopped plans.
        BillReminder::query()
            ->whereUser($userId)
            ->whereHas('occurrence', function ($query) {
                $query->where('status', '!=', 'planned')
                    ->orWhereHas('schedule', fn ($schedule) => $schedule->where('status', '!=', 'active'));
            })
            ->delete();

        $occurrences = RecurringExpenseOccurrence::query()
            ->where('status', 'planned')
            ->whereHas('schedule', function ($query) use ($userId) {
                $query->where('user_id', $userId)->where('status', 'active');
            })
            ->whereDate('occurrence_date', '<=', $today->addDays(30)->toDateString())
            ->with('schedule')
            ->orderBy('occurrence_date')
            ->orderBy('id')
            ->limit(1000)
            ->get();

        $inserted = 0;

        foreach ($occurrences as $occurrence) {
            $desired = $this->desiredReminder($occurrence, $occurrence->schedule, $today);
            $existing = BillReminder::query()
                ->where('recurring_expense_occurrence_id', $occurrence->id)
                ->get();

            foreach ($existing as $reminder) {
                $matches = $desired !== null
                    && $reminder->type === $desired['type']
                    && $reminder->window_days === $desired['window_days']
                    && $reminder->due_date->toDateString() === $desired['due_date'];

                if (! $matches) {
                    $reminder->delete();
                }
            }

            if ($desired === null) {
                continue;
            }

            $now = now();
            $inserted += BillReminder::query()->insertOrIgnore([[
                'user_id' => $userId,
                'recurring_expense_occurrence_id' => $occurrence->id,
                'type' => $desired['type'],
                'window_days' => $desired['window_days'],
                'due_date' => $desired['due_date'],
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
        }

        return $inserted;
    }

    /**
     * Used by the scheduler to generate reminders for every user in bounded
     * chunks. Safe to rerun; duplicate rows are ignored by the unique key.
     */
    public function syncAll(): int
    {
        $inserted = 0;

        User::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use (&$inserted) {
                foreach ($users as $user) {
                    $inserted += $this->syncForUser($user->id);
                }
            });

        return $inserted;
    }

    public function markRead(int $userId, int $reminderId): BillReminder
    {
        $reminder = BillReminder::query()
            ->whereUser($userId)
            ->findOrFail($reminderId);

        if ($reminder->read_at === null) {
            $reminder->forceFill(['read_at' => now()])->save();
        }

        return $reminder->fresh(['occurrence.schedule.category', 'occurrence.expense']);
    }

    public function markAllRead(int $userId): int
    {
        return BillReminder::query()
            ->whereUser($userId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function deleteForOccurrence(int $occurrenceId): void
    {
        BillReminder::query()
            ->where('recurring_expense_occurrence_id', $occurrenceId)
            ->delete();
    }

    public function deleteForSchedule(int $scheduleId): void
    {
        BillReminder::query()
            ->whereHas('occurrence', fn ($query) => $query->where('recurring_expense_id', $scheduleId))
            ->delete();
    }

    public function payload(BillReminder $reminder, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $occurrence = $reminder->occurrence;
        $schedule = $occurrence->schedule;
        $category = $schedule?->category;
        $dueDate = $reminder->due_date->toDateString();
        $daysUntilDue = (int) $today->startOfDay()->diffInDays($reminder->due_date->startOfDay(), false);
        $description = $schedule?->description ?? 'Scheduled bill';

        $message = match ($reminder->type) {
            self::TYPE_DUE => "{$description} is due today.",
            self::TYPE_OVERDUE => "{$description} is overdue by ".abs($daysUntilDue).' '.$this->pluralDays(abs($daysUntilDue)).'.',
            default => "{$description} is due in {$daysUntilDue} {$this->pluralDays($daysUntilDue)}.",
        };

        return [
            'id' => $reminder->id,
            'type' => $reminder->type,
            'type_label' => ucfirst($reminder->type),
            'window_days' => $reminder->window_days,
            'due_date' => $dueDate,
            'days_until_due' => $daysUntilDue,
            'message' => $message,
            'read_at' => $reminder->read_at?->toIso8601String(),
            'is_read' => $reminder->read_at !== null,
            'occurrence' => [
                'id' => $occurrence->id,
                'expected_amount' => $occurrence->expected_amount,
                'status' => $occurrence->status,
                'expense_id' => $occurrence->expense_id,
                'actual_amount' => $occurrence->expense?->amount,
                'payment_date' => $occurrence->expense?->expense_date?->toDateString(),
            ],
            'schedule' => [
                'id' => $schedule?->id,
                'description' => $description,
                'status' => $schedule?->status,
                'frequency' => $schedule?->frequency,
                'reminder_days_before' => $schedule?->reminder_days_before,
                'category' => $category === null ? null : [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, window_days: int, due_date: string}|null
     */
    private function desiredReminder(
        RecurringExpenseOccurrence $occurrence,
        $schedule,
        CarbonImmutable $today
    ): ?array {
        $due = $occurrence->occurrence_date->toDateString();
        $todayDate = $today->toDateString();

        if ($due < $todayDate) {
            return ['type' => self::TYPE_OVERDUE, 'window_days' => 0, 'due_date' => $due];
        }

        if ($due === $todayDate) {
            return ['type' => self::TYPE_DUE, 'window_days' => 0, 'due_date' => $due];
        }

        $window = (int) $schedule->reminder_days_before;
        $windowStart = $today->addDays($window)->toDateString();

        if ($window > 0 && $due <= $windowStart) {
            return ['type' => self::TYPE_UPCOMING, 'window_days' => $window, 'due_date' => $due];
        }

        return null;
    }

    private function pluralDays(int $days): string
    {
        return $days === 1 ? 'day' : 'days';
    }
}
