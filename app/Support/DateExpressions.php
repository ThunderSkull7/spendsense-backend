<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small, driver-aware SQL expressions for grouping date-only columns. The
 * values remain calendar months in UTC; no session timezone is applied.
 */
final class DateExpressions
{
    public static function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "substr(CAST({$column} AS TEXT), 1, 7)",
        };
    }
}
