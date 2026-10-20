<?php

namespace App\Http\Controllers;

use App\Http\Requests\CsvImportRequest;
use App\Services\CsvImportService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function __construct(private readonly CsvImportService $importService) {}

    /**
     * Preview a CSV import without committing.
     */
    public function preview(CsvImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $result = $this->importService->parseAndValidate($content, auth()->id());

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Execute a confirmed CSV import.
     */
    public function import(CsvImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $preview = $this->importService->parseAndValidate($content, auth()->id());

        // Only import valid, non-duplicate rows
        $importable = array_filter($preview['valid'], function (array $row) use ($preview) {
            $key = $this->rowKey($row);
            $isDuplicate = collect($preview['duplicates'])->contains(function ($dup) use ($key) {
                return $dup['row'] - 2 === $key; // Adjust for header offset
            });

            return !$isDuplicate;
        });

        $result = $this->importService->executeImport(array_values($importable), auth()->id());

        return response()->json([
            'success' => true,
            'data' => [
                'imported' => $result['created'],
                'skipped' => $result['skipped'],
                'total_valid' => count($preview['valid']),
                'duplicates_skipped' => count($preview['duplicates']),
                'errors' => $preview['errors'],
            ],
        ]);
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