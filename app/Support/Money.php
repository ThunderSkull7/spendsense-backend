<?php

namespace App\Support;

final class Money
{
    /**
     * Money is stored as PostgreSQL numeric/decimal. Summaries are calculated
     * in integer cents so they do not accumulate binary floating-point error.
     */
    public static function toCents(string|int|float $amount): int
    {
        $value = trim((string) $amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
