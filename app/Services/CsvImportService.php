<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 50;
    public const MAX_FIELD_LENGTH = 1000;

    /**
     * Parse and validate a CSV file for transaction import.
     *
     * @param  string  $csvContent
     * @param  int     $userId
     * @param  array   $mapping Optional custom column mapping [targetField => csvHeader]
     * @param  string  $duplicatePolicy 'skip' or 'import'
     * @return array
     */
    public function parseAndValidate(string $csvContent, int $userId, array $mapping = [], string $duplicatePolicy = 'skip'): array
    {
        // 1. Strip UTF-8 BOM if present
        if (str_starts_with($csvContent, Csv::BOM)) {
            $csvContent = substr($csvContent, strlen(Csv::BOM));
        }

        // 2. Validate encoding
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            $detected = mb_detect_encoding($csvContent, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($detected && $detected !== 'UTF-8') {
                $csvContent = mb_convert_encoding($csvContent, 'UTF-8', $detected);
            } else {
                throw ValidationException::withMessages([
                    'file' => 'The CSV file encoding is not supported. Please save the file in UTF-8 format.',
                ]);
            }
        }

        // 3. Parse CSV rows using robust streaming parser
        $parsed = $this->parseCsvStream($csvContent);
        $headers = $parsed['headers'];
        $rawRows = $parsed['rows'];

        if (empty($headers)) {
            throw ValidationException::withMessages([
                'file' => 'The CSV file is empty or missing a header row.',
            ]);
        }

        if (count($headers) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages([
                'file' => 'CSV file contains too many columns (maximum allowed is ' . self::MAX_COLUMNS . ').',
            ]);
        }

        // Check for duplicate headers
        $headerCounts = array_count_values($headers);
        $duplicateHeaders = [];
        foreach ($headerCounts as $headerName => $count) {
            if ($count > 1 && $headerName !== '') {
                $duplicateHeaders[] = $headerName;
            }
        }
        if (!empty($duplicateHeaders)) {
            throw ValidationException::withMessages([
                'file' => 'CSV header contains duplicate column names: ' . implode(', ', $duplicateHeaders) . '.',
            ]);
        }

        if (count($rawRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'CSV contains more than ' . self::MAX_ROWS . ' data rows. Please split into smaller files.',
            ]);
        }

        // 4. Resolve column mapping
        $resolvedMapping = $this->resolveColumnMapping($headers, $mapping);
        if (!isset($resolvedMapping['date']) || !isset($resolvedMapping['amount'])) {
            throw ValidationException::withMessages([
                'file' => 'CSV is missing required transaction columns. A date column and an amount column must be mapped.',
            ]);
        }

        // 5. Fetch available categories for validation
        $categories = Category::query()
            ->availableTo($userId, 'expense')
            ->get();
        $categoryLookup = [];
        foreach ($categories as $cat) {
            $categoryLookup[mb_strtolower(trim($cat->name))] = $cat;
        }

        $validRows = [];
        $errors = [];
        $previewRows = [];

        // 6. Validate each row
        foreach ($rawRows as $index => $rowArray) {
            $rowNumber = $index + 2; // +1 for 1-based, +1 for header line

            // Check field length limits
            $tooLong = false;
            foreach ($rowArray as $fieldVal) {
                if (strlen((string) $fieldVal) > self::MAX_FIELD_LENGTH) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'general',
                        'message' => 'Row contains a field exceeding maximum length of ' . self::MAX_FIELD_LENGTH . ' characters.',
                    ];
                    $tooLong = true;
                    break;
                }
            }
            if ($tooLong) {
                continue;
            }

            // Extract row according to mapping
            $rowMap = [];
            foreach ($headers as $colIdx => $colName) {
                $rowMap[$colName] = $rowArray[$colIdx] ?? '';
            }

            $validation = $this->validateRow($rowMap, $rowNumber, $resolvedMapping, $categoryLookup);

            if ($validation['valid']) {
                $data = $validation['data'];
                $data['original_row'] = $rowNumber;
                $validRows[] = $data;

                if (count($previewRows) < 10) {
                    $previewRows[] = [
                        'row' => $rowNumber,
                        'date' => $data['date'],
                        'type' => $data['type'],
                        'amount' => $data['amount'],
                        'description' => $data['type'] === 'expense' ? ($data['description'] ?? '') : ($data['source'] ?? ''),
                        'category' => $data['category_name'] ?? null,
                        'status' => 'valid',
                    ];
                }
            } else {
                $errors = array_merge($errors, $validation['errors']);

                if (count($previewRows) < 10) {
                    $previewRows[] = [
                        'row' => $rowNumber,
                        'date' => $rowMap[$resolvedMapping['date']] ?? '',
                        'type' => isset($resolvedMapping['type']) ? ($rowMap[$resolvedMapping['type']] ?? 'expense') : 'expense',
                        'amount' => $rowMap[$resolvedMapping['amount']] ?? '',
                        'description' => $rowMap[$resolvedMapping['description'] ?? ''] ?? '',
                        'category' => $rowMap[$resolvedMapping['category'] ?? ''] ?? '',
                        'status' => 'error',
                        'error_message' => $validation['errors'][0]['message'] ?? 'Invalid row',
                    ];
                }
            }
        }

        // 7. Check for duplicates
        $inImportDuplicates = $this->findInImportDuplicates($validRows);
        $existingDuplicates = $this->findExistingDuplicates($validRows, $userId);
        $allDuplicates = array_merge($inImportDuplicates, $existingDuplicates);

        // Update preview status for duplicates
        $dupRowNumbers = array_column($allDuplicates, 'row');
        foreach ($previewRows as &$pRow) {
            if ($pRow['status'] === 'valid' && in_array($pRow['row'], $dupRowNumbers, true)) {
                $pRow['status'] = 'duplicate';
            }
        }
        unset($pRow);

        $expenseCount = count(array_filter($validRows, fn ($r) => $r['type'] === 'expense'));
        $incomeCount = count(array_filter($validRows, fn ($r) => $r['type'] === 'income'));

        return [
            'headers' => $headers,
            'mapping' => $resolvedMapping,
            'preview_rows' => $previewRows,
            'valid' => $validRows,
            'errors' => $errors,
            'duplicates' => $allDuplicates,
            'duplicate_policy' => $duplicatePolicy,
            'summary' => [
                'total_rows' => count($rawRows),
                'valid_rows' => count($validRows),
                'error_rows' => count($errors),
                'duplicate_rows' => count($allDuplicates),
                'expense_count' => $expenseCount,
                'income_count' => $incomeCount,
            ],
        ];
    }

    /**
     * Execute the confirmed import within a safe database transaction.
     *
     * @param  array   $validRows
     * @param  int     $userId
     * @param  string  $duplicatePolicy 'skip' or 'import'
     * @param  array   $duplicateRowNumbers Array of row numbers identified as duplicates
     * @return array{created: int, skipped: int, errors: array}
     */
    public function executeImport(array $validRows, int $userId, string $duplicatePolicy = 'skip', array $duplicateRowNumbers = []): array
    {
        $created = 0;
        $skipped = 0;
        $dupSet = array_flip($duplicateRowNumbers);

        DB::transaction(function () use ($validRows, $userId, $duplicatePolicy, $dupSet, &$created, &$skipped) {
            foreach ($validRows as $row) {
                $rowNum = $row['original_row'] ?? null;
                $isDuplicate = $rowNum !== null && isset($dupSet[$rowNum]);

                if ($isDuplicate && $duplicatePolicy === 'skip') {
                    $skipped++;
                    continue;
                }

                if ($row['type'] === 'expense') {
                    Expense::create([
                        'user_id' => $userId,
                        'category_id' => $row['category_id'],
                        'amount' => $row['amount'],
                        'description' => $row['description'] ?? null,
                        'expense_date' => $row['date'],
                    ]);
                } else {
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
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => [],
        ];
    }

    /**
     * Parse CSV stream with autodetection of delimiter and safe enclosure handling.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    private function parseCsvStream(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        // Detect delimiter from sample line
        $sample = fgets($stream);
        rewind($stream);

        $delimiter = ',';
        if ($sample !== false) {
            $delims = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
            arsort($delims);
            $best = array_key_first($delims);
            if ($delims[$best] > 0) {
                $delimiter = $best;
            }
        }

        $headers = [];
        $rows = [];

        // Read header row
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            // Skip empty lines before header
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $headers = array_map(fn ($h) => trim(mb_strtolower((string) $h)), $row);
            break;
        }

        // Read data rows
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            // Skip blank lines
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $allBlank = true;
            foreach ($row as $val) {
                if (trim((string) $val) !== '') {
                    $allBlank = false;
                    break;
                }
            }
            if ($allBlank) {
                continue;
            }

            // Pad or slice to match header count
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            } else {
                $row = array_slice($row, 0, count($headers));
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $row);
        }

        fclose($stream);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Resolve target columns (date, amount, type, category, description, source, notes)
     * from headers or provided custom mapping.
     */
    private function resolveColumnMapping(array $headers, array $userMapping = []): array
    {
        $resolved = [];

        // Apply user-provided mappings if valid
        $headerSet = array_flip($headers);
        foreach (['date', 'amount', 'type', 'category', 'description', 'source', 'notes'] as $targetField) {
            if (isset($userMapping[$targetField])) {
                $mappedHeader = trim(mb_strtolower((string) $userMapping[$targetField]));
                if (isset($headerSet[$mappedHeader])) {
                    $resolved[$targetField] = $mappedHeader;
                }
            }
        }

        // Auto-detect standard aliases for unmapped fields
        $aliases = [
            'date' => ['date', 'transaction date', 'tx date', 'expense date', 'income date', 'posted date'],
            'amount' => ['amount', 'total', 'cost', 'sum', 'value', 'price'],
            'type' => ['type', 'transaction type', 'tx type', 'entry type'],
            'category' => ['category', 'cat', 'category name', 'category_name'],
            'description' => ['description', 'desc', 'merchant', 'title', 'payee', 'name', 'details'],
            'source' => ['source', 'payer', 'income source', 'income_source'],
            'notes' => ['notes', 'note', 'memo', 'comment', 'remarks'],
        ];

        foreach ($aliases as $target => $possibleAliases) {
            if (!isset($resolved[$target])) {
                foreach ($possibleAliases as $alias) {
                    if (isset($headerSet[$alias])) {
                        $resolved[$target] = $alias;
                        break;
                    }
                }
            }
        }

        return $resolved;
    }

    /**
     * Validate a single CSV row against domain rules and user category availability.
     */
    private function validateRow(array $rowMap, int $rowNumber, array $mapping, array $categoryLookup): array
    {
        $errors = [];

        // 1. Validate Date
        $rawDate = trim((string) ($rowMap[$mapping['date']] ?? ''));
        $parsedDate = $this->parseDate($rawDate);
        if (!$parsedDate) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'date',
                'message' => "Invalid date '{$rawDate}'. Expected YYYY-MM-DD or DD/MM/YYYY.",
            ];
        }

        // 2. Validate Amount
        $rawAmount = trim((string) ($rowMap[$mapping['amount']] ?? ''));
        $cleanAmount = str_replace([',', '$', '₹', '€', '£', ' '], '', $rawAmount);

        if (!is_numeric($cleanAmount) || (float) $cleanAmount <= 0) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'amount',
                'message' => 'Amount must be a positive number.',
            ];
        } elseif ((float) $cleanAmount > 99999999.99) {
            $errors[] = [
                'row' => $rowNumber,
                'field' => 'amount',
                'message' => 'Amount exceeds maximum allowed value of 99,999,999.99.',
            ];
        }

        // 3. Determine Type
        $rawType = isset($mapping['type']) ? trim(mb_strtolower((string) ($rowMap[$mapping['type']] ?? ''))) : '';
        if ($rawType !== '') {
            if (!in_array($rawType, ['expense', 'income'], true)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'type',
                    'message' => "Type must be either 'expense' or 'income'.",
                ];
                $type = 'expense';
            } else {
                $type = $rawType;
            }
        } else {
            // Infer type based on category or source column presence
            if (isset($mapping['source']) && trim((string) ($rowMap[$mapping['source']] ?? '')) !== '' && (!isset($mapping['category']) || trim((string) ($rowMap[$mapping['category']] ?? '')) === '')) {
                $type = 'income';
            } else {
                $type = 'expense';
            }
        }

        // Sanitize string fields against null bytes and control characters
        $rawDesc = isset($mapping['description']) ? $this->sanitizeText((string) ($rowMap[$mapping['description']] ?? '')) : '';
        $rawSource = isset($mapping['source']) ? $this->sanitizeText((string) ($rowMap[$mapping['source']] ?? '')) : '';
        $rawNotes = isset($mapping['notes']) ? $this->sanitizeText((string) ($rowMap[$mapping['notes']] ?? '')) : '';
        $rawCategory = isset($mapping['category']) ? trim((string) ($rowMap[$mapping['category']] ?? '')) : '';

        $data = [
            'type' => $type,
            'amount' => is_numeric($cleanAmount) && (float) $cleanAmount > 0 ? number_format((float) $cleanAmount, 2, '.', '') : '0.00',
            'date' => $parsedDate ?: $rawDate,
        ];

        // 4. Type-specific validations
        if ($type === 'expense') {
            if ($rawCategory === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'category',
                    'message' => 'Category is required for expenses.',
                ];
            } else {
                $catKey = mb_strtolower(trim($rawCategory));
                if (!isset($categoryLookup[$catKey])) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'category',
                        'message' => "Category '{$rawCategory}' does not exist in your available expense categories.",
                    ];
                } else {
                    $data['category_id'] = $categoryLookup[$catKey]->id;
                    $data['category_name'] = $categoryLookup[$catKey]->name;
                }
            }
            $data['description'] = $rawDesc !== '' ? mb_substr($rawDesc, 0, 255) : null;
        } else {
            $source = $rawSource !== '' ? $rawSource : ($rawDesc !== '' ? $rawDesc : '');
            if ($source === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'source',
                    'message' => 'Source or description is required for income.',
                ];
            }
            $data['source'] = mb_substr($source, 0, 255);
            $data['notes'] = $rawNotes !== '' ? mb_substr($rawNotes, 0, 255) : null;
        }

        return $errors ? ['valid' => false, 'errors' => $errors] : ['valid' => true, 'data' => $data];
    }

    /**
     * Parse date string supporting YYYY-MM-DD, DD/MM/YYYY, MM/DD/YYYY, and YYYY/MM/DD.
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Try standard formats
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y'];
        foreach ($formats as $fmt) {
            try {
                $d = CarbonImmutable::createFromFormat($fmt, $raw);
                if ($d && $d->format($fmt) === $raw) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // Try next format
            }
        }

        return null;
    }

    /**
     * Sanitize input strings against null bytes and ASCII control characters.
     */
    private function sanitizeText(string $text): string
    {
        // Strip null bytes and control characters (except space, tab, newline)
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($text)) ?? '';
    }

    /**
     * Check for duplicates within the current import dataset.
     */
    private function findInImportDuplicates(array $validRows): array
    {
        $duplicates = [];
        $seen = [];

        foreach ($validRows as $row) {
            $key = $this->rowKey($row);
            $rowNum = $row['original_row'];

            if (isset($seen[$key])) {
                $duplicates[] = [
                    'row' => $rowNum,
                    'duplicate_of' => $seen[$key],
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'date' => $row['date'],
                    'message' => "Duplicate row in import (identical to row {$seen[$key]})",
                ];
            } else {
                $seen[$key] = $rowNum;
            }
        }

        return $duplicates;
    }

    /**
     * Check for duplicates against user's existing database records.
     */
    private function findExistingDuplicates(array $validRows, int $userId): array
    {
        $duplicates = [];

        foreach ($validRows as $row) {
            if ($row['type'] === 'expense') {
                $query = Expense::query()
                    ->where('user_id', $userId)
                    ->where(function ($q) use ($row) {
                        $q->where('amount', $row['amount'])
                          ->orWhere('amount', (float) $row['amount']);
                    })
                    ->whereDate('expense_date', $row['date'])
                    ->where('category_id', $row['category_id']);

                if (!empty($row['description'])) {
                    $query->where('description', $row['description']);
                } else {
                    $query->where(function ($q) {
                        $q->whereNull('description')->orWhere('description', '');
                    });
                }
                $exists = $query->exists();
            } else {
                $query = Income::query()
                    ->where('user_id', $userId)
                    ->where(function ($q) use ($row) {
                        $q->where('amount', $row['amount'])
                          ->orWhere('amount', (float) $row['amount']);
                    })
                    ->whereDate('income_date', $row['date'])
                    ->where('source', $row['source']);

                if (!empty($row['notes'])) {
                    $query->where('notes', $row['notes']);
                } else {
                    $query->where(function ($q) {
                        $q->whereNull('notes')->orWhere('notes', '');
                    });
                }
                $exists = $query->exists();
            }

            if ($exists) {
                $duplicates[] = [
                    'row' => $row['original_row'],
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'date' => $row['date'],
                    'message' => 'Transaction already exists in your SpendSense records',
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
            $row['type'] === 'expense' ? ($row['category_id'] ?? '') : ($row['source'] ?? ''),
            $row['type'] === 'expense' ? ($row['description'] ?? '') : ($row['notes'] ?? ''),
        ]);
    }
}
