<?php

namespace App\Http\Controllers;

use App\Http\Requests\BudgetAlertMonthRequest;
use App\Models\BudgetAlert;
use App\Services\BudgetAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class BudgetAlertController extends Controller
{
    public function __construct(private readonly BudgetAlertService $alerts)
    {
    }

    public function index(BudgetAlertMonthRequest $request): JsonResponse
    {
        $month = $this->month($request->validated('month'));

        return response()->json([
            'success' => true,
            'data'    => $this->alerts->payloadForUser(auth()->id(), $month),
        ]);
    }

    public function read(BudgetAlert $budgetAlert): JsonResponse
    {
        if ($budgetAlert->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $alert = $this->alerts->markRead($budgetAlert);

        return response()->json([
            'success' => true,
            'data'    => $this->alerts->alertPayload($alert),
        ]);
    }

    public function readAll(BudgetAlertMonthRequest $request): JsonResponse
    {
        $month = $this->month($request->validated('month'));
        $updated = $this->alerts->markAllRead(auth()->id(), $month);

        return response()->json([
            'success' => true,
            'data'    => ['updated' => $updated, 'period_month' => $month->format('Y-m')],
        ]);
    }

    private function month(?string $value): CarbonImmutable
    {
        return $value === null
            ? CarbonImmutable::now('UTC')->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m-d', $value.'-01', 'UTC')->startOfMonth();
    }
}
