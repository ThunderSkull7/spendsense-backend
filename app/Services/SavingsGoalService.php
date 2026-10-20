<?php

namespace App\Services;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SavingsGoalService
{
    /**
     * Goals and contribution history are returned together for this small,
     * personal-finance workflow. Amounts are computed from the append-only
     * ledger, never from a mutable cached balance.
     */
    public function payloadForUser(int $userId, ?string $status = null): array
    {
        $goals = SavingsGoal::query()
            ->whereUser($userId)
            ->with(['contributions' => fn ($query) => $query
                ->orderByDesc('contributed_at')
                ->orderByDesc('id')])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get();

        $allPayloads = $goals->map(fn (SavingsGoal $goal) => $this->goalPayload($goal, true));

        $filtered = $status === null
            ? $allPayloads
            : $allPayloads->where('status', $status)->values();

        return [
            'goals'   => $filtered->all(),
            'summary' => $this->summary($allPayloads),
        ];
    }

    public function create(int $userId, array $data): array
    {
        $goal = SavingsGoal::create([
            'user_id'       => $userId,
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'target_amount' => $data['target_amount'],
            'target_date'   => $data['target_date'] ?? null,
            'status'        => SavingsGoal::STATUS_ACTIVE,
            'notes'         => $data['notes'] ?? null,
        ]);

        return $this->goalPayload($goal->fresh(), true);
    }

    public function update(SavingsGoal $goal, array $data): array
    {
        return DB::transaction(function () use ($goal, $data) {
            $locked = $this->lockGoal($goal->id);

            if ($locked->status === SavingsGoal::STATUS_ARCHIVED) {
                throw new ConflictHttpException('Archived goals are read-only. Restore the goal before editing it.');
            }

            foreach (['name', 'description', 'target_amount', 'target_date', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            $this->recalculateStatus($locked);
            $locked->save();

            return $this->goalPayload($locked->fresh(), true);
        });
    }

    public function archive(SavingsGoal $goal): array
    {
        return DB::transaction(function () use ($goal) {
            $locked = $this->lockGoal($goal->id);

            if ($locked->status !== SavingsGoal::STATUS_ARCHIVED) {
                $locked->status = SavingsGoal::STATUS_ARCHIVED;
                $locked->archived_at = now();
                $locked->save();
            }

            return $this->goalPayload($locked->fresh(), true);
        });
    }

    public function restore(SavingsGoal $goal): array
    {
        return DB::transaction(function () use ($goal) {
            $locked = $this->lockGoal($goal->id);

            if ($locked->status !== SavingsGoal::STATUS_ARCHIVED) {
                throw new ConflictHttpException('Only archived goals can be restored.');
            }

            $locked->archived_at = null;
            $locked->status = SavingsGoal::STATUS_ACTIVE;
            $this->recalculateStatus($locked);
            $locked->save();

            return $this->goalPayload($locked->fresh(), true);
        });
    }

    public function addEntry(SavingsGoal $goal, array $data, string $type): array
    {
        return DB::transaction(function () use ($goal, $data, $type) {
            $locked = $this->lockGoal($goal->id);

            if ($locked->status === SavingsGoal::STATUS_ARCHIVED) {
                throw new ConflictHttpException('Archived goals are read-only. Restore the goal before recording money.');
            }

            $amountCents = Money::toCents($data['amount']);

            if ($type === SavingsGoalContribution::TYPE_CORRECTION
                && $amountCents > $this->balanceCents($locked->id)) {
                throw ValidationException::withMessages([
                    'amount' => 'A correction cannot exceed the amount currently saved for this goal.',
                ]);
            }

            SavingsGoalContribution::create([
                'savings_goal_id' => $locked->id,
                'type'            => $type,
                'amount'          => Money::fromCents($amountCents),
                'note'            => $data['note'] ?? null,
                'contributed_at'  => $data['contributed_at'] ?? CarbonImmutable::today()->toDateString(),
            ]);

            $this->recalculateStatus($locked);
            $locked->save();

            return $this->goalPayload($locked->fresh(), true);
        });
    }

    public function goalPayload(SavingsGoal $goal, bool $withHistory = false): array
    {
        $targetCents = Money::toCents((string) $goal->target_amount);
        $savedCents = $this->balanceCents($goal->id);
        $remainingCents = max(0, $targetCents - $savedCents);
        $overfundedCents = max(0, $savedCents - $targetCents);
        $progressPercent = $targetCents > 0
            ? round(($savedCents / $targetCents) * 100, 2)
            : 0.0;

        $payload = [
            'id'                       => $goal->id,
            'name'                     => $goal->name,
            'description'              => $goal->description,
            'target_amount'            => Money::fromCents($targetCents),
            'saved_amount'             => Money::fromCents($savedCents),
            'remaining_amount'         => Money::fromCents($remainingCents),
            'overfunded_amount'        => Money::fromCents($overfundedCents),
            'progress_percent'         => $progressPercent,
            'progress_bar_percent'     => min(100, $progressPercent),
            'status'                   => $goal->status,
            'target_date'              => $goal->target_date?->toDateString(),
            'is_overdue'               => $goal->status === SavingsGoal::STATUS_ACTIVE
                && $goal->target_date !== null
                && $goal->target_date->lt(CarbonImmutable::today()),
            'notes'                    => $goal->notes,
            'completed_at'             => $goal->completed_at?->toIso8601String(),
            'archived_at'              => $goal->archived_at?->toIso8601String(),
            'contribution_count'       => $goal->contributions()->count(),
            'created_at'               => $goal->created_at?->toIso8601String(),
            'updated_at'               => $goal->updated_at?->toIso8601String(),
        ];

        if ($withHistory) {
            $entries = $goal->relationLoaded('contributions')
                ? $goal->contributions
                : $goal->contributions()
                    ->orderByDesc('contributed_at')
                    ->orderByDesc('id')
                    ->get();

            $payload['contributions'] = $entries->map(fn (SavingsGoalContribution $entry) => [
                'id'             => $entry->id,
                'type'           => $entry->type,
                'amount'         => (string) $entry->amount,
                'note'           => $entry->note,
                'contributed_at' => $entry->contributed_at?->toDateString(),
                'created_at'     => $entry->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $payload;
    }

    private function summary(Collection $goals): array
    {
        $current = $goals->whereIn('status', [
            SavingsGoal::STATUS_ACTIVE,
            SavingsGoal::STATUS_COMPLETED,
        ]);

        $totalTargetCents = $current->sum(fn (array $goal) => Money::toCents($goal['target_amount']));
        $totalSavedCents = $current->sum(fn (array $goal) => Money::toCents($goal['saved_amount']));

        return [
            'active_count'     => $goals->where('status', SavingsGoal::STATUS_ACTIVE)->count(),
            'completed_count'  => $goals->where('status', SavingsGoal::STATUS_COMPLETED)->count(),
            'archived_count'   => $goals->where('status', SavingsGoal::STATUS_ARCHIVED)->count(),
            'total_target'     => Money::fromCents($totalTargetCents),
            'total_saved'      => Money::fromCents($totalSavedCents),
            'total_remaining'  => Money::fromCents(max(0, $totalTargetCents - $totalSavedCents)),
        ];
    }

    private function recalculateStatus(SavingsGoal $goal): void
    {
        if ($goal->status === SavingsGoal::STATUS_ARCHIVED) {
            return;
        }

        $targetCents = Money::toCents((string) $goal->target_amount);
        $savedCents = $this->balanceCents($goal->id);

        if ($savedCents >= $targetCents) {
            $goal->status = SavingsGoal::STATUS_COMPLETED;
            $goal->completed_at ??= now();
        } else {
            $goal->status = SavingsGoal::STATUS_ACTIVE;
            $goal->completed_at = null;
        }
    }

    private function balanceCents(int $goalId): int
    {
        $totals = DB::table('savings_goal_contributions')
            ->where('savings_goal_id', $goalId)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'contribution' THEN amount ELSE 0 END), 0) as contributed")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'correction' THEN amount ELSE 0 END), 0) as corrected")
            ->first();

        return Money::toCents((string) $totals->contributed) - Money::toCents((string) $totals->corrected);
    }

    private function lockGoal(int $goalId): SavingsGoal
    {
        return SavingsGoal::query()->whereKey($goalId)->lockForUpdate()->firstOrFail();
    }
}
