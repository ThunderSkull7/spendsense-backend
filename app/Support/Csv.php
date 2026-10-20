<?php

namespace App\Support;

final class Csv
{
    /**
     * CSV formula-injection protection. Text that a spreadsheet could mistake
     * for a formula is prefixed with an apostrophe. Plain numbers (including
     * negative amounts) are left untouched so exported totals remain useful.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = str_replace("\0", '', (string) $value);

        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $text)) {
            return $text;
        }

        // Spreadsheet programs may ignore leading whitespace while deciding
        // whether a cell is a formula. Prefix the whole cell in that case.
        if (preg_match('/^[\s]*[=+\-@\t\r\n]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }

    public static function filename(string $stem, string $extension = 'csv'): string
    {
        $safeStem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem) ?? 'spendsense-export';
        $safeStem = trim($safeStem, '-_.') ?: 'spendsense-export';
        $safeExtension = preg_replace('/[^a-z0-9]+/', '', strtolower($extension)) ?: 'csv';

        return $safeStem.'.'.$safeExtension;
    }
}
