<?php

namespace App\Http\Controllers;

use App\Services\FinancialHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialHealthController extends Controller
{
    public function __construct(private readonly FinancialHealthService $health) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => 'nullable|string',
            'year'  => 'nullable|integer',
        ]);

        $monthStr = $validated['month'] ?? null;
        $month = null;
        $year = isset($validated['year']) ? (int) $validated['year'] : null;

        if ($monthStr !== null && is_numeric($monthStr)) {
            $month = (int) $monthStr;
            $monthStr = null;
        }

        $data = $this->health->forUser(
            (int) auth()->id(),
            $monthStr,
            $month,
            $year
        );

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}

