<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\ReportCsvService;
use App\Services\ReportService;
use App\Support\Csv;
use App\Support\ReportSections;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportCsvService $csv
    ) {}

    public function show(ReportRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->build($request),
        ]);
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        $report = $this->build($request);
        $rows = $this->csv->rows($report);
        $filename = Csv::filename(
            'spendsense-report-'.$report['period']['from'].'-to-'.$report['period']['to']
        );

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(ReportRequest $request): array
    {
        $validated = $request->validated();

        return $this->reports->build(
            auth()->id(),
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['compare'] ?? 'previous',
            ReportSections::parse($validated['sections'] ?? null)
        );
    }
}
