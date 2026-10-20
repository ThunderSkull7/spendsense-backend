<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashFlowForecastRequest;
use App\Services\CashFlowForecastService;
use Illuminate\Http\JsonResponse;

class CashFlowForecastController extends Controller
{
    public function __construct(private readonly CashFlowForecastService $forecast) {}

    public function show(CashFlowForecastRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->forecast->forUser(
                auth()->id(),
                (int) ($validated['horizon'] ?? 30),
                $validated['as_of'] ?? null
            ),
        ]);
    }
}
