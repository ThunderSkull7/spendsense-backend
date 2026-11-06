<?php

namespace App\Support;

final class Csv
{
    /**
     * UTF-8 Byte Order Mark (BOM) for spreadsheet application compatibility (Excel, etc.).
     */
    public const BOM = "\xEF\xBB\xBF";

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

        // Legitimate numeric values (positive or negative, integer or decimal)
        // are preserved without apostrophe so spreadsheet calculations remain functional.
        if (preg_match('/^-?\d+(?:\.\d+)?$/', trim($text))) {
            return trim($text);
        }

        // Spreadsheet programs (Excel, LibreOffice Calc, Google Sheets, etc.) may ignore
        // leading whitespace and control characters before evaluating formulas.
        // Neutralize formulas beginning with =, +, -, @, pipe commands (|), or percent (%) variables.
        if (preg_match('/^[\s\x00-\x20]*[=+\-@|%]/u', $text) || preg_match('/^[\t\r\n]/', $text)) {
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
