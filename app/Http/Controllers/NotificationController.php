<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationFilterRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Get all notifications for the authenticated user.
     */
    public function index(NotificationFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->notifications->payloadForUser(auth()->id(), $validated),
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(string $type, int $id): JsonResponse
    {
        try {
            $notification = $this->notifications->markRead(auth()->id(), $type, $id);

            return response()->json([
                'success' => true,
                'data' => $notification,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mark all notifications as read (or filtered subset).
     */
    public function markAllRead(\App\Http\Requests\NotificationFilterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = $this->notifications->markAllRead(auth()->id(), $validated);

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $count,
                'filter' => $validated['filter'] ?? 'all',
                'month' => $validated['month'] ?? null,
            ],
        ]);
    }
}