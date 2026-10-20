<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\FinancialCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,paused,cancelled',
            'link' => 'nullable|in:linked,standalone,all',
            'search' => 'nullable|string|max:120',
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->subscriptions->payloadForUser(auth()->id(), $validated),
        ]);
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->subscriptions->create(auth()->id(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeOwner($subscription);
        $validated = $request->validate([
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->subscriptions->payloadForSubscription(
                $subscription,
                isset($validated['as_of'])
                    ? FinancialCalendar::parseDate($validated['as_of'], 'as_of')
                    : null
            ),
        ]);
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeOwner($subscription);

        return response()->json([
            'success' => true,
            'data' => $this->subscriptions->update(
                auth()->id(),
                $subscription,
                $request->validated()
            ),
        ]);
    }

    private function authorizeOwner(Subscription $subscription): void
    {
        if ($subscription->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
