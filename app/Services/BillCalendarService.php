<?php

namespace App\Services;

use App\Models\RecurringExpenseOccurrence;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BillCalendarService
{
    public function __construct(private readonly RecurringExpenseService $expenses) {}

    /**
     * One month of expected bills plus the current upcoming/overdue panels.
     * The calendar query is bounded to the month and eager-loads only the
     * relations needed to render it.
     */
    public function month(int $userId, ?string $month, ?string $status, ?int $categoryId): array
    {
        $month = $month ?: CarbonImmutable::today('UTC')->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');

        if (! $start || $start->format('Y-m') !== $month) {
            throw ValidationException::withMessages([
                'month' => 'Use a valid month in YYYY-MM format.',
            ]);
        }

        $today = CarbonImmutable::today('UTC');
        $start = $start->startOfMonth();
        $end = $start->endOfMonth();

        $query = RecurringExpenseOccurrence::query()
            ->with(['schedule.category', 'expense'])
            ->whereHas('schedule', function ($schedule) use ($userId, $categoryId) {
                $schedule->where('user_id', $userId);
                if ($categoryId !== null) {
                    $schedule->where('category_id', $categoryId);
                }
            })
            ->whereDate('occurrence_date', '>=', $start->toDateString())
        ->whereDate('occurrence_date', '<=', $end->toDateString());

        $this->applyStatusFilter($query, $status, $today);

        $entries = $query
            ->orderBy('occurrence_date')
            ->orderBy('id')
            ->get()
            ->map(fn (RecurringExpenseOccurrence $occurrence) => $this->expenses->occurrencePayload($occurrence, $today))
            ->values()
            ->all();

        return [
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'first_weekday' => (int) $start->dayOfWeekIso,
            'days_in_month' => $start->daysInMonth,
            'entries' => $entries,
            'summary' => $this->summarize($entries),
            'upcoming' => $this->upcoming($userId, 30, $categoryId),
            'overdue' => $this->overdue($userId, $categoryId),
            'filters' => [
                'status' => $status,
                'category_id' => $categoryId,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(int $userId, int $days = 30, ?int $categoryId = null): array
    {
        $today = CarbonImmutable::today('UTC');

        $query = RecurringExpenseOccurrence::query()
            ->with(['schedule.category', 'expense'])
            ->where('status', 'planned')
            ->whereHas('schedule', function ($schedule) use ($userId, $categoryId) {
                $schedule->where('user_id', $userId)->where('status', 'active');
                if ($categoryId !== null) {
                    $schedule->where('category_id', $categoryId);
                }
            })
            ->whereDate('occurrence_date', '>=', $today->toDateString())
            ->whereDate('occurrence_date', '<=', $today->addDays($days)->toDateString());

        return $query
            ->orderBy('occurrence_date')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (RecurringExpenseOccurrence $occurrence) => $this->expenses->occurrencePayload($occurrence, $today))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function overdue(int $userId, ?int $categoryId = null): array
    {
        $today = CarbonImmutable::today('UTC');

        $query = RecurringExpenseOccurrence::query()
            ->with(['schedule.category', 'expense'])
            ->where('status', 'planned')
            ->whereDate('occurrence_date', '<', $today->toDateString())
            ->whereHas('schedule', function ($schedule) use ($userId, $categoryId) {
                $schedule->where('user_id', $userId)->where('status', 'active');
                if ($categoryId !== null) {
                    $schedule->where('category_id', $categoryId);
                }
            });

        return $query
            ->orderByDesc('occurrence_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (RecurringExpenseOccurrence $occurrence) => $this->expenses->occurrencePayload($occurrence, $today))
            ->values()
            ->all();
    }

    private function applyStatusFilter($query, ?string $status, CarbonImmutable $today): void
    {
        if ($status === null || $status === '') {
            return;
        }

        if ($status === 'overdue') {
            $query->where('status', 'planned')
                ->whereDate('occurrence_date', '<', $today->toDateString());

            return;
        }

        if ($status === 'due') {
            $query->where('status', 'planned')
                ->whereDate('occurrence_date', '=', $today->toDateString());

            return;
        }

        if ($status === 'unpaid' || $status === 'planned') {
            $query->where('status', 'planned');

            return;
        }

        $query->where('status', $status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, int|string>
     */
    private function summarize(array $entries): array
    {
        $expected = 0;
        $paid = 0;
        $unpaid = 0;
        $overdue = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $expectedCents = Money::toCents($entry['expected_amount']);
            $status = $entry['status'];

            if (in_array($status, ['planned', 'paid'], true)) {
                $expected += $expectedCents;
            }

            if ($status === 'planned') {
                $unpaid += $expectedCents;
            }

            if ($entry['display_status'] === 'overdue') {
                $overdue += $expectedCents;
            }

            if ($status === 'paid') {
                $paid += $entry['actual_amount'] === null
                    ? $expectedCents
                    : Money::toCents($entry['actual_amount']);
            }

            if ($status === 'skipped') {
                $skipped += $expectedCents;
            }
        }

        return [
            'expected_total' => Money::fromCents($expected),
            'paid_total' => Money::fromCents($paid),
            'unpaid_total' => Money::fromCents($unpaid),
            'overdue_total' => Money::fromCents($overdue),
            'skipped_total' => Money::fromCents($skipped),
            'entry_count' => count($entries),
        ];
    }
}
