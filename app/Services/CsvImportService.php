<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Support\Csv;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB
    public const MAX_ROWS = 5000;
    public const REQUIRED_COLUMNS = ['date', 'amount', 'description', 'type'];

    /**
     * Parse and validate a CSV file for transaction import.
     *
     * @param  string  $csvContent
     * @return array{valid: array<int, array>, errors: array<int, array>, duplicates: array<int, array>, summary: array}
     */
    public function parseAndValidate(string $csvContent, int $userId): array
    {
        $rows = $this->parseCsv($csvContent);

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => "CSV contains more than " . self::MAX_ROWS . " rows. Please split into smaller files.",
            ]);
        }

        $categories = Category::query()
            ->availableTo($userId, 'expense')
            ->get()
            ->keyBy('name')
            ->toArray();

        $validRows = [];
        $errors = [];
        $duplicates = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for header, +1 for 1-based
            $validation = $this->validateRow($row, $rowNumber, $categories, $userId);

            if ($validation['valid']) {
                $validRows[] = $validation['data'];
            } else {
                $errors = array_merge($errors, $validation['errors']);
            }
        }

        // Check for duplicates within the import
        $duplicates = $this->findDuplicates($validRows, $userId);

        // Check for duplicates against existing data
        $existingDuplicates = $this->findExistingDuplicates($validRows, $userId);

        return [
            'valid' => $validRows,
            'errors' => $errors,
            'duplicates' => array_merge($duplicates, $existingDuplicates),
            'summary' => [
                'total_rows' => count($rows),
                'valid_rows' => count($validRows),
                'error_rows' => count($errors),
                'duplicate_rows' => count($duplicates) + count($existingDuplicates),
            ],
        ];
    }

    /**
     * Execute the confirmed import.
     *
     * @param  array<int, array>  $validRows
     * @return array{created: int, skipped: int}
     */
    public function executeImport(array $validRows, int $userId): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($validRows as $row) {
            try {
                DB::transaction(function () use ($row, $userId, &$created, &$skipped) {
                    if ($row['type'] === 'expense') {
                        // Check for existing duplicate
                        $exists = Expense::query()
                            ->where('user_id', $userId)
                            ->where('amount', $row['amount'])
                            ->where('expense_date', $row['date'])
                            ->where('category_id', $row['category_id'])
                            ->where('description', $row['description'] ?? '')
                            ->exists();

                        if ($exists) {
                            $skipped++;
                            return;
                        }

                        Expense::create([
                            'user_id' => $userId,
                            'category_id' => $row['category_id'],
                            'amount' => $row['amount'],
                            'description' => $row['description'] ?? null,
                            'expense_date' => $row['date'],
                        ]);
                    } else {
                        // Check for existing duplicate
                        $exists = Income::query()
                            ->where('user_id', $userId)
                            ->where('amount', $row['amount'])
                            ->where('income_date', $row['date'])
                            ->where('source', $row['source'])
                            ->where('notes', $row['notes'] ?? '')
                            ->exists();

                        if ($exists) {
                            $skipped++;
                            return;
                        }

                        Income::create([
                            'user_id' => $userId,
                            'amount' => $row['amount'],
                            'source' => $row['source'],
                            'income_date' => $row['date'],
                            'frequency' => 'one-time',
                            'notes' => $row['notes'] ?? null,
                        ]);
                    }
                    $created++;
                });
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $content): array
    {
        $lines = explode("\n", $content);
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $fields = $this->parseCsvLine($line);
            if (count($fields) > 0) {
                $rows[] = $fields;
            }
        }

        if (empty($rows)) {
            return [];
        }

        // First row is header
        $headers = array_map('strtolower', array_map('trim', $rows[0]));
        $dataRows = array_slice($rows, 1);

        $result = [];
        foreach ($dataRows as $row) {
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            $result[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }

        return $result;
    }

    private function parseCsvLine(string $line): array
    {
        $fields = [];
        $field = '';
        $inQuotes = false;

        for ($i = 0; $i < strlen($line); $i++) {
            $char = $line[$i];

            if ($char === '"') {
                if ($inQuotes && $i + 1 < strlen($line) && $line[$i + 1] === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuotes = !$inQuotes;
                }
            } elseif ($char === ',' && !$inQuotes) {
                $fields[] = trim($field);
                $field = '';
            } else {
                $field .= $char;
            }
        }
        $fields[] = trim($field);

        return array_map(fn ($f) => trim($f, '"'), $fields);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{valid: bool, data?: array, errors?: array<int, array>}
     */
    private function validateRow(array $row, int $rowNumber, array $categories, int $userId): array
    {
        $errors = [];

        // Required columns
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!isset($row[$col]) || trim((string) $row[$col]) === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => $col,
                    'message' => "Missing required column: {$col}",
                ];
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $type = strtolower(trim((string) $row['type']));
        if (!in_array($type, ['expense', 'income'], true)) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'type',
                'message' => "Type must be 'expense' or 'income'",
            ];
        }

        // Amount
        $amount = trim((string) $row['amount']);
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'amount',
                'message' => 'Amount must be a positive number',
            ];
        }

        if ((float) $amount > 99999999.99) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'amount',
                'message' => 'Amount exceeds maximum allowed value',
            ];
        }

        // Date
        $date = trim((string) $row['date']);
        try {
            $dateObj = CarbonImmutable::createFromFormat('Y-m-d', $date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
                throw new \Exception();
            }
            $date = $dateObj->toDateString();
        } catch (\Throwable) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'date',
                'message' => 'Date must be in YYYY-MM-DD format',
            ];
        }

        $data = [
            'type' => $type,
            'amount' => $amount,
            'date' => $date,
        ];

        if ($type === 'expense') {
            $description = trim((string) ($row['description'] ?? ''));
            $categoryName = trim((string) ($row['category'] ?? ''));

            if ($categoryName === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'category',
                    'message' => 'Category is required for expenses',
                ];
            } elseif (!isset($categories[$categoryName])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'category',
                    'message' => "Category '{$categoryName}' does not exist or is not an expense category",
                ];
            } else {
                $data['category_id'] = $categories[$categoryName]['id'];
                $data['description'] = $description ?: null;
            }
        } else {
            $source = trim((string) ($row['source'] ?? ''));
            $notes = trim((string) ($row['notes'] ?? ''));

            if ($source === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'source',
                    'message' => 'Source is required for income',
                ];
            }

            $data['source'] = $source;
            $data['notes'] = $notes ?: null;
        }

        return $errors ? ['valid' => false, 'errors' => $errors] : ['valid' => true, 'data' => $data];
    }

    /**
     * @param  array<int, array>  $validRows
     * @return array<int, array>
     */
    private function findDuplicates(array $validRows, int $userId): array
    {
        $duplicates = [];
        $seen = [];

        foreach ($validRows as $index => $row) {
            $key = $this->rowKey($row);
            if (isset($seen[$key])) {
                $duplicates[] = [
                    'row' => $index + 2,
                    'duplicate_of' => $seen[$key] + 2,
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'date' => $row['date'],
                    'message' => 'Duplicate row in import (same date, amount, type, and description/source)',
                ];
            } else {
                $seen[$key] = $index;
            }
        }

        return $duplicates;
    }

    /**
     * @param  array<int, array>  $validRows
     * @return array<int, array>
     */
    private function findExistingDuplicates(array $validRows, int $userId): array
    {
        $duplicates = [];

        foreach ($validRows as $index => $row) {
            if ($row['type'] === 'expense') {
                $exists = Expense::query()
                    ->where('user_id', $userId)
                    ->where('amount', $row['amount'])
                    ->where('expense_date', $row['date'])
                    ->where('category_id', $row['category_id'])
                    ->where('description', $row['description'] ?? '')
                    ->exists();
            } else {
                $exists = Income::query()
                    ->where('user_id', $userId)
                    ->where('amount', $row['amount'])
                    ->where('income_date', $row['date'])
                    ->where('source', $row['source'])
                    ->where('notes', $row['notes'] ?? '')
                    ->exists();
            }

            if ($exists) {
                $duplicates[] = [
                    'row' => $index + 2,
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'date' => $row['date'],
                    'message' => 'Transaction already exists in your records',
                ];
            }
        }

        return $duplicates;
    }

    private function rowKey(array $row): string
    {
        return implode('|', [
            $row['type'],
            $row['amount'],
            $row['date'],
            $row['type'] === 'expense' ? ($row['description'] ?? '') : ($row['source'] ?? ''),
        ]);
    }
}