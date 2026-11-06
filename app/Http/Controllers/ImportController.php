<?php

namespace App\Http\Controllers;

use App\Http\Requests\CsvImportRequest;
use App\Services\CsvImportService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function __construct(private readonly CsvImportService $importService) {}

    /**
     * Preview a CSV import without committing any database records.
     */
    public function preview(CsvImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $mapping = $request->input('mapping', []);
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        }

        $duplicatePolicy = $request->input('duplicate_policy', 'skip');
        if (!in_array($duplicatePolicy, ['skip', 'import'], true)) {
            $duplicatePolicy = 'skip';
        }

        $result = $this->importService->parseAndValidate($content, auth()->id(), $mapping, $duplicatePolicy);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Execute a confirmed CSV import within a safe database transaction.
     */
    public function import(CsvImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $mapping = $request->input('mapping', []);
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        }

        $duplicatePolicy = $request->input('duplicate_policy', 'skip');
        if (!in_array($duplicatePolicy, ['skip', 'import'], true)) {
            $duplicatePolicy = 'skip';
        }

        // Revalidate completely on server
        $preview = $this->importService->parseAndValidate($content, auth()->id(), $mapping, $duplicatePolicy);

        $duplicateRowNumbers = array_column($preview['duplicates'], 'row');

        $result = $this->importService->executeImport(
            $preview['valid'],
            auth()->id(),
            $duplicatePolicy,
            $duplicateRowNumbers
        );

        return response()->json([
            'success' => true,
            'data' => [
                'imported' => $result['created'],
                'skipped' => $result['skipped'],
                'total_valid' => count($preview['valid']),
                'duplicates_skipped' => $duplicatePolicy === 'skip' ? $result['skipped'] : 0,
                'errors' => $preview['errors'],
            ],
        ]);
    }
}
