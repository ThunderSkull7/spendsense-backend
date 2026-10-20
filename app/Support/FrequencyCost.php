<?php

namespace App\Support;

/**
 * Normalizes recurring costs without inventing transactions. Whole-period
 * multipliers are intentionally used so estimates are explainable:
 * weekly = 52 charges, biweekly = 26, monthly = 12, and so on.
 */
final class FrequencyCost
{
    public const ANNUAL_MULTIPLIERS = [
        'daily' => 365,
        'weekly' => 52,
        'biweekly' => 26,
        'monthly' => 12,
        'quarterly' => 4,
        'half-yearly' => 2,
        'yearly' => 1,
    ];

    public static function annualCents(string|int|float $amount, string $frequency): int
    {
        $multiplier = self::ANNUAL_MULTIPLIERS[$frequency] ?? 0;

        return Money::toCents($amount) * $multiplier;
    }

    /**
     * Annual cost divided into 12 equal months, rounded half up to a cent.
     */
    public static function monthlyCents(string|int|float $amount, string $frequency): int
    {
        return intdiv(self::annualCents($amount, $frequency) + 6, 12);
    }

    public static function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'biweekly' => 'Every two weeks',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'half-yearly' => 'Every six months',
            'yearly' => 'Yearly',
            default => $frequency,
        };
    }
}
