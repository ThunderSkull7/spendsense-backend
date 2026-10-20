<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardPreferencesRequest;
use App\Models\DashboardPreference;
use App\Support\DashboardWidgets;
use Illuminate\Http\JsonResponse;

class DashboardPreferenceController extends Controller
{
    public function index(): JsonResponse
    {
        $preference = DashboardPreference::query()
            ->where('user_id', auth()->id())
            ->first();

        return response()->json([
            'success' => true,
            'data' => $this->payload($preference),
        ]);
    }

    public function update(UpdateDashboardPreferencesRequest $request): JsonResponse
    {
        $widgets = DashboardWidgets::normalize($request->validated()['widgets']);

        $preference = DashboardPreference::query()->updateOrCreate(
            ['user_id' => auth()->id()],
            ['widgets' => $widgets]
        );

        return response()->json([
            'success' => true,
            'data' => $this->payload($preference, false),
        ]);
    }

    public function destroy(): JsonResponse
    {
        DashboardPreference::query()
            ->where('user_id', auth()->id())
            ->delete();

        return response()->json([
            'success' => true,
            'data' => $this->payload(null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?DashboardPreference $preference, ?bool $isDefault = null): array
    {
        return [
            'widgets' => $preference
                ? DashboardWidgets::normalize($preference->widgets ?? [])
                : DashboardWidgets::defaults(),
            'is_default' => $isDefault ?? $preference === null,
            'labels' => DashboardWidgets::LABELS,
            'updated_at' => $preference?->updated_at?->toIso8601String(),
        ];
    }
}
