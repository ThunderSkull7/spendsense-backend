<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class ReportSections
{
    public const ALL = [
        'comparison',
        'monthly',
        'income_sources',
        'categories',
        'budgets',
        'savings',
        'commitments',
        'upcoming_bills',
        'subscriptions',
        'forecast',
        'net_worth',
        'expense_review',
    ];

    /**
     * @return list<string>
     */
    public static function parse(?string $sections): array
    {
        if ($sections === null || trim($sections) === '') {
            return self::ALL;
        }

        $values = array_values(array_unique(array_filter(array_map(
            fn (string $value): string => trim($value),
            explode(',', $sections)
        ))));

        $invalid = array_diff($values, self::ALL);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'sections' => 'The report contains an unsupported section: '.implode(', ', $invalid).'.',
            ]);
        }

        return array_values(array_intersect(self::ALL, $values));
    }
}
