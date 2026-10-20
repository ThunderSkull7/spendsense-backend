<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpendingInsightsRequest;
use App\Services\SpendingInsightsService;
use Illuminate\Http\JsonResponse;

class SpendingInsightsController extends Controller
{
    public function __construct(private readonly SpendingInsightsService $insights) {}

    public function show(SpendingInsightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->insights->forUser(
                auth()->id(),
                $validated['from'] ?? null,
                $validated['to'] ?? null
            ),
        ]);
    }
}
