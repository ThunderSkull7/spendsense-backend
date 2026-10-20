<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarRecurringExpenseRequest;
use App\Http\Requests\PayRecurringExpenseOccurrenceRequest;
use App\Http\Requests\PreviewRecurringExpenseRequest;
use App\Http\Requests\StoreRecurringExpenseRequest;
use App\Http\Requests\UpdateRecurringExpenseRequest;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Services\BillCalendarService;
use App\Services\RecurringExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RecurringExpenseController extends Controller
{
    public function __construct(
        private readonly RecurringExpenseService $service,
        private readonly BillCalendarService $calendar
    ) {}

    public function index(): JsonResponse
    {
        $schedules = RecurringExpense::query()
            ->whereUser(auth()->id())
            ->with(['category', 'occurrences.expense'])
            ->orderByDesc('created_at')
            ->get();

        $payloads = $schedules
            ->map(fn (RecurringExpense $schedule) => $this->service->schedulePayload($schedule))
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

    public function preview(PreviewRecurringExpenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->service->preview(
                $validated['frequency'],
                (string) $validated['amount'],
                $validated['start_date'],
                $validated['end_date'] ?? null
            ),
        ]);
    }

    public function calendar(CalendarRecurringExpenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->calendar->month(
                auth()->id(),
                $validated['month'] ?? null,
                $validated['status'] ?? null,
                isset($validated['category_id']) ? (int) $validated['category_id'] : null
            ),
        ]);
    }

    public function store(StoreRecurringExpenseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $schedule = DB::transaction(function () use ($validated) {
            $this->ensureExpenseCategory((int) $validated['category_id']);

            $schedule = RecurringExpense::create([
                'user_id' => auth()->id(),
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category_id' => $validated['category_id'],
                'frequency' => $validated['frequency'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'status' => 'active',
                'reminder_days_before' => $validated['reminder_days_before'] ?? 3,
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->service->generateInitial($schedule);

            return $schedule;
        });

        $schedule->load(['category', 'occurrences.expense']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ], 201);
    }

    public function show(int $recurringExpense): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringExpense);
        $schedule->load(['category', 'occurrences.expense']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    public function occurrences(int $recurringExpense): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringExpense);
        $schedule->load(['category', 'occurrences.expense']);

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

    public function update(UpdateRecurringExpenseRequest $request, int $recurringExpense): JsonResponse
    {
        $validated = $request->validated();
        $schedule = $this->findOwnedSchedule($recurringExpense);

        $updated = DB::transaction(function () use ($validated, $schedule) {
            $locked = RecurringExpense::query()
                ->where('user_id', auth()->id())
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'stopped') {
                throw new ConflictHttpException('A stopped schedule cannot be edited.');
            }

            if (array_key_exists('category_id', $validated)) {
                $this->ensureExpenseCategory((int) $validated['category_id']);
            }

            $merged = [
                'description' => $validated['description'] ?? $locked->description,
                'amount' => $validated['amount'] ?? $locked->amount,
                'category_id' => $validated['category_id'] ?? $locked->category_id,
                'frequency' => $validated['frequency'] ?? $locked->frequency,
                'start_date' => $validated['start_date'] ?? $locked->start_date->toDateString(),
                'end_date' => array_key_exists('end_date', $validated)
                    ? $validated['end_date']
                    : $locked->end_date?->toDateString(),
                'reminder_days_before' => $validated['reminder_days_before'] ?? $locked->reminder_days_before,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $locked->notes,
            ];

            $shapeChanged = $this->service->toCents($locked->amount) !== $this->service->toCents($merged['amount'])
                || $locked->frequency !== $merged['frequency']
                || $locked->start_date->toDateString() !== $merged['start_date']
                || $locked->end_date?->toDateString() !== $merged['end_date'];

            $locked->update($merged);

            if ($shapeChanged) {
                $this->service->regenerateFuture($locked);
            }

            return $locked;
        });

        $updated->load(['category', 'occurrences.expense']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($updated, true),
        ]);
    }

    public function pause(int $recurringExpense): JsonResponse
    {
        return $this->statusResponse($this->service->pause($this->findOwnedSchedule($recurringExpense), auth()->id()));
    }

    public function resume(int $recurringExpense): JsonResponse
    {
        return $this->statusResponse($this->service->resume($this->findOwnedSchedule($recurringExpense), auth()->id()));
    }

    public function stop(int $recurringExpense): JsonResponse
    {
        return $this->statusResponse($this->service->stop($this->findOwnedSchedule($recurringExpense), auth()->id()));
    }

    public function pay(
        PayRecurringExpenseOccurrenceRequest $request,
        int $recurringExpense,
        int $occurrence
    ): JsonResponse {
        $schedule = $this->findOwnedSchedule($recurringExpense);
        $occurrenceModel = $schedule->occurrences()->whereKey($occurrence)->firstOrFail();
        $validated = $request->validated();

        $paid = $this->service->pay(
            $schedule,
            $occurrenceModel,
            auth()->id(),
            isset($validated['actual_amount']) ? (string) $validated['actual_amount'] : null,
            $validated['paid_date'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $this->service->occurrencePayload($paid),
        ]);
    }

    public function skip(int $recurringExpense, int $occurrence): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringExpense);
        $occurrenceModel = $schedule->occurrences()->whereKey($occurrence)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->service->occurrencePayload(
                $this->service->skip($schedule, $occurrenceModel, auth()->id())
            ),
        ]);
    }

    public function markUnpaid(int $recurringExpense, int $occurrence): JsonResponse
    {
        $schedule = $this->findOwnedSchedule($recurringExpense);
        $occurrenceModel = $schedule->occurrences()->whereKey($occurrence)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->service->occurrencePayload(
                $this->service->markUnpaid($schedule, $occurrenceModel, auth()->id())
            ),
        ]);
    }

    private function statusResponse(RecurringExpense $schedule): JsonResponse
    {
        $schedule->load(['category', 'occurrences.expense']);

        return response()->json([
            'success' => true,
            'data' => $this->service->schedulePayload($schedule, true),
        ]);
    }

    /**
     * Scoped lookup: another user's schedule is indistinguishable from a
     * missing one, so the response cannot confirm that it exists.
     */
    private function findOwnedSchedule(int $id): RecurringExpense
    {
        return RecurringExpense::query()
            ->whereUser(auth()->id())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function ensureExpenseCategory(int $categoryId): void
    {
        $category = Category::query()->find($categoryId);

        if ($category === null || $category->type !== 'expense') {
            throw ValidationException::withMessages([
                'category_id' => 'The category must be of type expense.',
            ]);
        }
    }
}
