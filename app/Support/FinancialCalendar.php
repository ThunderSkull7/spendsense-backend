<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Stage 4 reporting uses date-only values and UTC calendar boundaries. This is
 * deliberately explicit: the application does not store a per-user timezone,
 * so server-local or browser-local month boundaries would make the same report
 * change depending on where it is rendered.
 */
final class FinancialCalendar
{
    public const TIMEZONE = 'UTC';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(self::TIMEZONE);
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    /**
     * Parse an inclusive YYYY-MM month selector into the first day of that
     * month at 00:00 UTC.
     */
    public static function parseMonth(string $value, string $field = 'month'): CarbonImmutable
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $value, self::TIMEZONE);

        if (! $month || $month->format('Y-m') !== $value) {
            throw ValidationException::withMessages([
                $field => 'Use a valid month in YYYY-MM format.',
            ]);
        }

        return $month->startOfMonth();
    }

    public static function parseDate(string $value, string $field = 'date'): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $field => 'Use a valid date in YYYY-MM-DD format.',
            ]);
        }

        return $date;
    }

    /**
     * @return array<int, string> Inclusive list of YYYY-MM keys.
     */
    public static function monthKeys(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = [];

        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $keys[] = $month->format('Y-m');
        }

        return $keys;
    }

    public static function assertMonthRange(CarbonImmutable $start, CarbonImmutable $end, int $maxMonths = 24): void
    {
        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'to' => 'The end month must be on or after the start month.',
            ]);
        }

        if (count(self::monthKeys($start, $end)) > $maxMonths) {
            throw ValidationException::withMessages([
                'from' => "Select no more than {$maxMonths} calendar months.",
            ]);
        }
    }
}
