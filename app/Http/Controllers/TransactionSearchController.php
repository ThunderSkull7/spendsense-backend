<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionSearchRequest;
use App\Services\TransactionSearchService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionSearchController extends Controller
{
    public function __construct(private readonly TransactionSearchService $search) {}

    public function index(TransactionSearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $this->search->paginate(auth()->id(), $validated, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'q' => $validated['q'] ?? null,
                'type' => $validated['type'] ?? 'all',
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'min_amount' => $validated['min_amount'] ?? null,
                'max_amount' => $validated['max_amount'] ?? null,
                'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
                'sort' => $validated['sort'] ?? 'date_desc',
            ],
        ]);
    }

    public function export(TransactionSearchRequest $request): StreamedResponse
    {
        $validated = $request->validated();
        $rows = $this->search->exportRows(auth()->id(), $validated);

        $from = $validated['from'] ?? 'start';
        $to = $validated['to'] ?? 'today';
        $filename = Csv::filename("spendsense-transactions-{$from}-to-{$to}");

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
}
