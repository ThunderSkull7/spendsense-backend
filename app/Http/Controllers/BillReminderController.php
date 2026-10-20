<?php

namespace App\Http\Controllers;

use App\Services\BillReminderService;
use Illuminate\Http\JsonResponse;

class BillReminderController extends Controller
{
    public function __construct(private readonly BillReminderService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->payloadForUser(auth()->id()),
        ]);
    }

    public function read(int $reminder): JsonResponse
    {
        $reminder = $this->service->markRead(auth()->id(), $reminder);

        return response()->json([
            'success' => true,
            'data' => $this->service->payload($reminder),
        ]);
    }

    public function readAll(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $this->service->markAllRead(auth()->id()),
            ],
        ]);
    }
}
