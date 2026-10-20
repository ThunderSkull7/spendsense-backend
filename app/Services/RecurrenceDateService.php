<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RecurrenceDateService
{
    public const MAX_OCCURRENCES = 366;

    public const FREQUENCIES = [
        'daily',
        'weekly',
        'biweekly',
        'monthly',
        'quarterly',
        'half-yearly',
        'yearly',
    ];

    /**
     * Calendar dates are calculated in UTC from the original start date. The
     * values are date-only, so daylight-saving transitions cannot add or remove
     * a day, and weekly schedules stay exactly seven calendar days apart.
     *
     * @return array<int, string> YYYY-MM-DD dates
     */
    public function occurrenceDates(string $frequency, string $startDate, string $endDate): array
    {
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw ValidationException::withMessages([
                'frequency' => 'Unsupported recurrence frequency.',
            ]);
        }

        $start = $this->parseDate($startDate, 'start_date');
        $end = $this->parseDate($endDate, 'end_date');

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the start date.',
            ]);
        }

        $dates = in_array($frequency, ['daily', 'weekly', 'biweekly'], true)
            ? $this->dayBasedDates($frequency, $start, $end)
            : $this->monthBasedDates($frequency, $start, $end);

        if (count($dates) > self::MAX_OCCURRENCES) {
            throw ValidationException::withMessages([
                'end_date' => 'This schedule would create more than '.self::MAX_OCCURRENCES.' occurrences. Choose a shorter date range.',
            ]);
        }

        return $dates;
    }

    /**
     * Open-ended schedules are materialized for a rolling horizon rather than
     * infinite future dates. A past start date does not backfill history for an
     * open-ended plan; instead the first future date keeps the original
     * recurrence anchor (for example, a monthly 15th stays on the 15th).
     */
    public function generationStartDate(string $frequency, string $startDate, ?string $endDate): string
    {
        $start = $this->parseDate($startDate, 'start_date');

        if ($endDate !== null && $endDate !== '') {
            return $start->toDateString();
        }

        $today = CarbonImmutable::today('UTC');

        return $start->lt($today)
            ? $this->firstOnOrAfter($frequency, $start, $today)->toDateString()
            : $start->toDateString();
    }

    public function generationEndDate(string $frequency, string $startDate, ?string $endDate): string
    {
        if ($endDate !== null && $endDate !== '') {
            return $endDate;
        }

        $windowStart = $this->parseDate(
            $this->generationStartDate($frequency, $startDate, null),
            'start_date'
        );

        $windowEnd = in_array($frequency, ['daily', 'weekly', 'biweekly'], true)
            ? $windowStart->addDays(365)
            : $windowStart->addYearNoOverflow();

        return $windowEnd->toDateString();
    }

    /**
     * Advance from the original anchor without changing its day-of-month
     * semantics. Carbon is always called with the cumulative offset from the
     * anchor so a 31st can recover after February and a leap day can return.
     */
    public function firstOnOrAfter(
        string $frequency,
        CarbonImmutable $start,
        CarbonImmutable $target
    ): CarbonImmutable {
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw ValidationException::withMessages([
                'frequency' => 'Unsupported recurrence frequency.',
            ]);
        }

        if ($target->lte($start)) {
            return $start;
        }

        if (in_array($frequency, ['daily', 'weekly', 'biweekly'], true)) {
            $step = match ($frequency) {
                'daily' => 1,
                'weekly' => 7,
                'biweekly' => 14,
            };

            $days = (int) $start->diffInDays($target);
            $steps = intdiv($days + $step - 1, $step);

            return $start->addDays($steps * $step);
        }

        $step = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'half-yearly' => 6,
            'yearly' => 12,
        };

        $offset = $step;
        $date = $start->addMonthsNoOverflow($offset);
        while ($date->lt($target)) {
            $offset += $step;
            $date = $start->addMonthsNoOverflow($offset);
        }

        return $date;
    }

    public function today(): string
    {
        return CarbonImmutable::today('UTC')->toDateString();
    }

    private function parseDate(string $value, string $field): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => 'Use a valid date in YYYY-MM-DD format.',
            ]);
        }

        return $date;
    }

    /**
     * @return array<int, string>
     */
    private function dayBasedDates(string $frequency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $step = match ($frequency) {
            'daily' => 1,
            'weekly' => 7,
            'biweekly' => 14,
        };

        $dates = [];
        for ($date = $start; $date->lte($end); $date = $date->addDays($step)) {
            $dates[] = $date->toDateString();
            if (count($dates) > self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $dates;
    }

    /**
     * Anchor each date to the original start day. A schedule starting on the
     * 31st therefore falls on the last day of shorter months and returns to
     * the 31st afterwards instead of drifting permanently to the 28th.
     *
     * @return array<int, string>
     */
    private function monthBasedDates(string $frequency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'half-yearly' => 6,
            'yearly' => 12,
        };

        $dates = [];
        $monthStart = $start->startOfMonth();

        for ($offset = 0; ; $offset += $months) {
            $month = $monthStart->addMonthsNoOverflow($offset);
            $date = $month->setDay(min($start->day, $month->daysInMonth));

            if ($date->gt($end)) {
                break;
            }

            if ($date->gte($start)) {
                $dates[] = $date->toDateString();
                if (count($dates) > self::MAX_OCCURRENCES) {
                    break;
                }
            }
        }

        return $dates;
    }
}
