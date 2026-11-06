<?php

namespace App\Services;

use App\Models\NetWorthEntry;
use App\Models\NetWorthValuation;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DebtService
{
    /**
     * Retrieve all debts and repayment history for a user.
     * All calculations are strictly derived from immutable valuations.
     *
     * @return array<string, mixed>
     */
    public function payloadForUser(int $userId, ?string $status = null): array
    {
        $entries = NetWorthEntry::query()
            ->whereUser($userId)
            ->where('type', 'liability')
            ->with(['valuations' => fn ($query) => $query
                ->orderByDesc('valuation_date')
                ->orderByDesc('id')])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        $allPayloads = $entries->map(fn (NetWorthEntry $entry) => $this->debtPayload($entry, true));

        $filtered = $status === null
            ? $allPayloads
            : $allPayloads->filter(fn (array $d) => $status === 'all' || $d['status'] === $status)->values();

        return [
            'debts'   => $filtered->all(),
            'summary' => $this->summary($allPayloads),
        ];
    }

    public function show(int $userId, int $debtId): array
    {
        $entry = $this->findDebtForUser($userId, $debtId);
        $entry->load(['valuations' => fn ($query) => $query
            ->orderByDesc('valuation_date')
            ->orderByDesc('id')]);

        return $this->debtPayload($entry, true);
    }

    public function create(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $originalAmountCents = Money::toCents((string) $data['original_amount']);
            $currentBalanceCents = isset($data['current_balance'])
                ? Money::toCents((string) $data['current_balance'])
                : $originalAmountCents;

            if ($currentBalanceCents > $originalAmountCents) {
                throw ValidationException::withMessages([
                    'current_balance' => 'Current balance cannot exceed the original principal amount.',
                ]);
            }

            $interestRate = isset($data['interest_rate']) && $data['interest_rate'] !== null && $data['interest_rate'] !== ''
                ? (float) $data['interest_rate']
                : null;
            $minPayment = isset($data['minimum_payment']) && $data['minimum_payment'] !== null && $data['minimum_payment'] !== ''
                ? Money::toCents((string) $data['minimum_payment'])
                : null;

            $meta = [
                'original_principal_cents' => $originalAmountCents,
                'interest_rate'            => $interestRate,
                'minimum_payment_cents'    => $minPayment,
                'user_note'                => $data['notes'] ?? null,
            ];

            $notesText = json_encode($meta);

            $entry = NetWorthEntry::create([
                'user_id'     => $userId,
                'name'        => $data['name'],
                'type'        => 'liability',
                'category'    => $data['category'] ?? 'loan',
                'institution' => $data['lender'] ?? $data['institution'] ?? null,
                'notes'       => $notesText,
                'status'      => 'active',
            ]);

            $date = $data['start_date'] ?? FinancialCalendar::today()->toDateString();

            NetWorthValuation::create([
                'net_worth_entry_id' => $entry->id,
                'value_amount'       => Money::fromCents($currentBalanceCents),
                'valuation_date'     => $date,
                'note'               => 'Opening loan balance',
            ]);

            return $this->debtPayload($entry->fresh(), true);
        });
    }

    public function update(int $userId, int $debtId, array $data): array
    {
        return DB::transaction(function () use ($userId, $debtId, $data) {
            $entry = $this->findDebtForUser($userId, $debtId, true);

            if ($entry->status === 'archived') {
                throw new ConflictHttpException('Archived debts are read-only. Restore the debt before editing it.');
            }

            $meta = $this->parseMeta($entry->notes);

            if (array_key_exists('name', $data)) {
                $entry->name = $data['name'];
            }
            if (array_key_exists('lender', $data) || array_key_exists('institution', $data)) {
                $entry->institution = $data['lender'] ?? $data['institution'];
            }
            if (array_key_exists('interest_rate', $data)) {
                $meta['interest_rate'] = $data['interest_rate'] !== null && $data['interest_rate'] !== ''
                    ? (float) $data['interest_rate']
                    : null;
            }
            if (array_key_exists('minimum_payment', $data)) {
                $meta['minimum_payment_cents'] = $data['minimum_payment'] !== null && $data['minimum_payment'] !== ''
                    ? Money::toCents((string) $data['minimum_payment'])
                    : null;
            }
            if (array_key_exists('notes', $data)) {
                $meta['user_note'] = $data['notes'];
            }

            $entry->notes = json_encode($meta);
            $entry->save();

            return $this->debtPayload($entry->fresh(), true);
        });
    }

    public function recordRepayment(int $userId, int $debtId, array $data): array
    {
        return DB::transaction(function () use ($userId, $debtId, $data) {
            $entry = $this->findDebtForUser($userId, $debtId, true);

            if ($entry->status === 'archived') {
                throw new ConflictHttpException('Archived debts are read-only. Restore the debt before recording payments.');
            }

            $repaymentCents = Money::toCents((string) $data['amount']);
            if ($repaymentCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Repayment amount must be greater than zero.',
                ]);
            }

            // Get current latest balance
            $latestValuation = $entry->valuations()
                ->orderByDesc('valuation_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $currentBalanceCents = $latestValuation !== null
                ? Money::toCents((string) $latestValuation->value_amount)
                : 0;

            if ($currentBalanceCents <= 0) {
                throw new ConflictHttpException('This debt has already been fully repaid.');
            }

            if ($repaymentCents > $currentBalanceCents) {
                $maxFormatted = Money::fromCents($currentBalanceCents);
                throw ValidationException::withMessages([
                    'amount' => "Repayment of " . Money::fromCents($repaymentCents) . " exceeds the outstanding balance of {$maxFormatted}.",
                ]);
            }

            $newBalanceCents = max(0, $currentBalanceCents - $repaymentCents);
            $date = $data['payment_date'] ?? FinancialCalendar::today()->toDateString();
            $note = $data['note'] ?? 'Repayment recorded';

            // Check if valuation exists on the same day
            $existingToday = NetWorthValuation::where('net_worth_entry_id', $entry->id)
                ->where('valuation_date', $date)
                ->first();

            if ($existingToday) {
                $existingToday->update([
                    'value_amount' => Money::fromCents($newBalanceCents),
                    'note'         => $existingToday->note . '; ' . $note . ' (' . Money::fromCents($repaymentCents) . ')',
                ]);
            } else {
                NetWorthValuation::create([
                    'net_worth_entry_id' => $entry->id,
                    'value_amount'       => Money::fromCents($newBalanceCents),
                    'valuation_date'     => $date,
                    'note'               => $note . ' (' . Money::fromCents($repaymentCents) . ')',
                ]);
            }

            return $this->debtPayload($entry->fresh(), true);
        });
    }

    public function archive(int $userId, int $debtId): array
    {
        return DB::transaction(function () use ($userId, $debtId) {
            $entry = $this->findDebtForUser($userId, $debtId, true);

            if ($entry->status !== 'archived') {
                $entry->status = 'archived';
                $entry->archived_at = now();
                $entry->save();
            }

            return $this->debtPayload($entry->fresh(), true);
        });
    }

    public function restore(int $userId, int $debtId): array
    {
        return DB::transaction(function () use ($userId, $debtId) {
            $entry = $this->findDebtForUser($userId, $debtId, true);

            if ($entry->status !== 'archived') {
                throw new ConflictHttpException('Only archived debts can be restored.');
            }

            $entry->archived_at = null;
            $entry->status = 'active';
            $entry->save();

            return $this->debtPayload($entry->fresh(), true);
        });
    }

    public function debtPayload(NetWorthEntry $entry, bool $withHistory = false): array
    {
        $meta = $this->parseMeta($entry->notes);

        $valuations = $entry->relationLoaded('valuations')
            ? $entry->valuations
            : $entry->valuations()->orderBy('valuation_date')->orderBy('id')->get();

        // Earliest valuation vs latest valuation
        $sortedAsc = $valuations->sortBy([['valuation_date', 'asc'], ['id', 'asc']])->values();
        $sortedDesc = $valuations->sortBy([['valuation_date', 'desc'], ['id', 'desc']])->values();

        $latestVal = $sortedDesc->first();
        $currentBalanceCents = $latestVal !== null ? Money::toCents((string) $latestVal->value_amount) : 0;

        $originalAmountCents = $meta['original_principal_cents'] ?? null;
        if ($originalAmountCents === null || $originalAmountCents <= 0) {
            $firstVal = $sortedAsc->first();
            $originalAmountCents = $firstVal !== null ? Money::toCents((string) $firstVal->value_amount) : $currentBalanceCents;
        }

        $repaidCents = max(0, $originalAmountCents - $currentBalanceCents);
        $repaymentProgress = $originalAmountCents > 0
            ? round(($repaidCents / $originalAmountCents) * 100, 1)
            : 0.0;

        $effectiveStatus = $entry->status;
        if ($effectiveStatus === 'active' && $currentBalanceCents === 0 && $originalAmountCents > 0) {
            $effectiveStatus = 'completed';
        }

        // Payoff projection estimate based on monthly repayment pace
        $payoffEstimate = null;
        if ($currentBalanceCents > 0 && $sortedAsc->count() >= 2) {
            $firstDate = CarbonImmutable::parse($sortedAsc->first()->valuation_date);
            $lastDate = CarbonImmutable::parse($sortedDesc->first()->valuation_date);
            $monthsSpan = max(1, $firstDate->diffInMonths($lastDate));
            $monthlyPaceCents = (int) round($repaidCents / $monthsSpan);

            if ($monthlyPaceCents > 0) {
                $estimatedMonthsRemaining = (int) ceil($currentBalanceCents / $monthlyPaceCents);
                $projectedDate = CarbonImmutable::today()->addMonthsNoOverflow($estimatedMonthsRemaining)->endOfMonth();
                $payoffEstimate = [
                    'estimated_months_remaining' => $estimatedMonthsRemaining,
                    'projected_payoff_date'      => $projectedDate->toDateString(),
                    'monthly_repayment_pace'     => Money::fromCents($monthlyPaceCents),
                    'assumption'                 => 'Simple linear estimate based on historical repayment pace of ' . Money::fromCents($monthlyPaceCents) . '/month, excluding interest charges.',
                ];
            }
        }

        $payload = [
            'id'                         => $entry->id,
            'name'                       => $entry->name,
            'category'                   => $entry->category,
            'lender'                     => $entry->institution,
            'institution'                => $entry->institution,
            'original_principal'         => Money::fromCents($originalAmountCents),
            'current_balance'            => Money::fromCents($currentBalanceCents),
            'repaid_amount'              => Money::fromCents($repaidCents),
            'repayment_progress_percent' => min(100.0, $repaymentProgress),
            'interest_rate'              => $meta['interest_rate'] ?? null,
            'minimum_payment'            => isset($meta['minimum_payment_cents']) && $meta['minimum_payment_cents'] !== null
                ? Money::fromCents($meta['minimum_payment_cents'])
                : null,
            'status'                     => $effectiveStatus,
            'notes'                      => $meta['user_note'] ?? null,
            'payoff_estimate'            => $payoffEstimate,
            'valuation_count'            => $valuations->count(),
            'created_at'                 => $entry->created_at?->toIso8601String(),
            'updated_at'                 => $entry->updated_at?->toIso8601String(),
            'archived_at'                => $entry->archived_at?->toIso8601String(),
        ];

        if ($withHistory) {
            $payload['repayments'] = $sortedDesc->map(fn (NetWorthValuation $v) => [
                'id'             => $v->id,
                'balance_amount' => (string) $v->value_amount,
                'valuation_date' => $v->valuation_date?->toDateString(),
                'note'           => $v->note,
                'created_at'     => $v->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $payload;
    }

    private function summary(Collection $debts): array
    {
        $active = $debts->where('status', 'active');
        $completed = $debts->where('status', 'completed');
        $archived = $debts->where('status', 'archived');

        $totalOriginalCents = $debts->whereIn('status', ['active', 'completed'])->sum(fn (array $d) => Money::toCents($d['original_principal']));
        $totalBalanceCents = $active->sum(fn (array $d) => Money::toCents($d['current_balance']));
        $totalRepaidCents = $debts->whereIn('status', ['active', 'completed'])->sum(fn (array $d) => Money::toCents($d['repaid_amount']));

        return [
            'active_count'           => $active->count(),
            'completed_count'        => $completed->count(),
            'archived_count'         => $archived->count(),
            'total_original'         => Money::fromCents($totalOriginalCents),
            'total_current_balance'  => Money::fromCents($totalBalanceCents),
            'total_repaid'           => Money::fromCents($totalRepaidCents),
        ];
    }

    private function findDebtForUser(int $userId, int $debtId, bool $lock = false): NetWorthEntry
    {
        $query = NetWorthEntry::query()
            ->whereUser($userId)
            ->where('type', 'liability')
            ->whereKey($debtId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $entry = $query->first();
        if ($entry === null) {
            throw new NotFoundHttpException('Debt liability record not found.');
        }

        return $entry;
    }

    private function parseMeta(?string $notes): array
    {
        if ($notes === null || $notes === '') {
            return [];
        }

        $decoded = json_decode($notes, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['user_note' => $notes];
    }
}

