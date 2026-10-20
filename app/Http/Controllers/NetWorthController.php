<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNetWorthEntryRequest;
use App\Http\Requests\StoreNetWorthValuationRequest;
use App\Http\Requests\UpdateNetWorthEntryRequest;
use App\Models\NetWorthEntry;
use App\Services\NetWorthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    public function __construct(private readonly NetWorthService $netWorth) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,archived,all',
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->index(
                auth()->id(),
                $validated['status'] ?? 'active',
                $validated['as_of'] ?? null
            ),
        ]);
    }

    public function store(StoreNetWorthEntryRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->netWorth->create(auth()->id(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, NetWorthEntry $netWorthEntry): JsonResponse
    {
        $this->authorizeOwner($netWorthEntry);
        $validated = $request->validate([
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->show(
                auth()->id(),
                $netWorthEntry,
                $validated['as_of'] ?? null
            ),
        ]);
    }

    public function update(UpdateNetWorthEntryRequest $request, NetWorthEntry $netWorthEntry): JsonResponse
    {
        $this->authorizeOwner($netWorthEntry);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->update(
                auth()->id(),
                $netWorthEntry,
                $request->validated()
            ),
        ]);
    }

    public function archive(NetWorthEntry $netWorthEntry): JsonResponse
    {
        $this->authorizeOwner($netWorthEntry);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->archive(auth()->id(), $netWorthEntry),
        ]);
    }

    public function restore(NetWorthEntry $netWorthEntry): JsonResponse
    {
        $this->authorizeOwner($netWorthEntry);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->restore(auth()->id(), $netWorthEntry),
        ]);
    }

    public function storeValuation(
        StoreNetWorthValuationRequest $request,
        NetWorthEntry $netWorthEntry
    ): JsonResponse {
        $this->authorizeOwner($netWorthEntry);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->addValuation(
                auth()->id(),
                $netWorthEntry,
                $request->validated()
            ),
        ], 201);
    }

    public function trend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|between:2,24',
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->netWorth->trend(
                auth()->id(),
                (int) ($validated['months'] ?? 12),
                $validated['as_of'] ?? null
            ),
        ]);
    }

    private function authorizeOwner(NetWorthEntry $entry): void
    {
        if ($entry->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
