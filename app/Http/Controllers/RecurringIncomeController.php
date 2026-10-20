<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrectRecurringIncomeReceiptRequest;
use App\Http\Requests\PreviewRecurringIncomeRequest;
use App\Http\Requests\ReceiveRecurringIncomeOccurrenceRequest;
use App\Http\Requests\StoreRecurringIncomeRequest;
use App\Http\Requests\UpdateRecurringIncomeRequest;
use App\Models\RecurringIncome;
use App\Services\RecurringIncomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecurringIncomeController extends Controller
{
    public function __construct(private readonly RecurringIncomeService $service) {}

    public function index(): JsonResponse
    {
        $schedules = RecurringIncome::query()
            ->whereUser(auth()->id())
            ->with(['occurrences.income'])
            ->orderByDesc('created_at')
            ->get();

        $payloads = $schedules
            ->map(fn (RecurringIncome $schedule) => $this->service->schedulePayload($schedule))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'schedules' => $payloads,
                'summary' => $this->service->aggregate($payloads),
            ],
        ]);
    }

    public function preview(PreviewRecurringIncomeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->service->preview(
                $validated['frequency'],
                (string) $validated['amount'],
                $validated['start_date'],
                $validated['end_date']
            ),
        ]);
    }

    public function store(StoreRecurringIncomeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $schedule = DB::transaction(function () use ($validated) {
            $schedule = RecurringIncome::create([
                'user_id' => auth()->id(),
                'name' => $validated['name'],
                'amount' => $validated['amount'],
                'frequency' => $validated['frequency'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->service->generateInitial($schedule);

            return $schedule;
        });

        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ], 201);
    }

    public function show(int $recurringIncome): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    public function occurrences(int $recurringIncome): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => [
                'schedule' => $this->service->schedulePayload($schedule),
                'occurrences' => $schedule->occurrences
                    ->map(fn ($occurrence) => $this->service->occurrencePayload($occurrence))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function update(UpdateRecurringIncomeRequest $request, int $recurringIncome): JsonResponse
    {
        $validated = $request->validated();
        $schedule = $this->findOwnedSchedule($recurringIncome);

        $updated = DB::transaction(function () use ($validated, $schedule) {
            $locked = RecurringIncome::query()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be edited.');
            }

            $merged = [
                'name' => $validated['name'] ?? $locked->name,
                'amount' => $validated['amount'] ?? $locked->amount,
                'frequency' => $validated['frequency'] ?? $locked->frequency,
                'start_date' => $validated['start_date'] ?? $locked->start_date->toDateString(),
                'end_date' => $validated['end_date'] ?? $locked->end_date->toDateString(),
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $locked->notes,
            ];

            // Validate the complete resulting range before mutating anything.
            $this->service->occurrenceDates(
                $merged['frequency'],
                $merged['start_date'],
                $merged['end_date']
            );

            if ($validated === []) {
                return $locked;
            }

            $locked->update($merged);

            // Only rebuild when a cadence or amount field actually changed.
            // A notes-only edit must not churn occurrence IDs.
            $affectsOccurrences = array_intersect_key(
                $validated,
                array_flip(['amount', 'frequency', 'start_date', 'end_date'])
            ) !== [];

            if ($affectsOccurrences) {
                // Only future, unreceived occurrences are eligible for rebuild.
                $this->service->regenerateFuture($locked);
            }

            return $locked;
        });

        $updated->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($updated, true),
        ]);
    }

    public function pause(int $recurringIncome): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $schedule = $this->service->pause($schedule);
        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    public function resume(int $recurringIncome): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $schedule = $this->service->resume($schedule);
        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    public function stop(int $recurringIncome): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $schedule = $this->service->stop($schedule);
        $schedule->load(['occurrences.income']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    public function receive(
        ReceiveRecurringIncomeOccurrenceRequest $request,
        int $recurringIncome,
        int $occurrence
    ): JsonResponse {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $validated = $request->validated();

        $occurrence = $this->service->receive(
            $schedule,
            $occurrence,
            isset($validated['actual_amount']) ? (string) $validated['actual_amount'] : null,
            $validated['actual_received_date'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $this->service->occurrencePayload($occurrence),
        ]);
    }

    public function correctReceipt(
        CorrectRecurringIncomeReceiptRequest $request,
        int $recurringIncome,
        int $occurrence
    ): JsonResponse {
        $schedule = $this->findOwnedSchedule($recurringIncome);
        $validated = $request->validated();

        $occurrence = $this->service->correctReceipt(
            $schedule,
            $occurrence,
            (string) $validated['actual_amount'],
            $validated['actual_received_date']
        );

        return response()->json([
            'success' => true,
            'data' => $this->service->occurrencePayload($occurrence),
        ]);
    }

    /**
     * Scoped lookup: another user's schedule is indistinguishable from a
     * missing one, so the response cannot confirm that it exists.
     */
    private function findOwnedSchedule(int $id): RecurringIncome
    {
        return RecurringIncome::query()
            ->whereUser(auth()->id())
            ->findOrFail($id);
    }
}
