<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Expense;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BudgetAlertService
{
    public const DEFAULT_THRESHOLDS = [50, 80, 100];

    /**
     * Reconcile persisted threshold alerts for one calendar month and return
     * the user's inbox. Reading the endpoint is an idempotent sync, matching
     * the bill-reminder behaviour used in Stage 2.
     */
    public function payloadForUser(int $userId, CarbonImmutable $month): array
    {
        $period = $month->startOfMonth()->toDateString();
        $this->sync($userId, $month);

        $alerts = BudgetAlert::query()
            ->whereUser($userId)
            ->where('period_month', $period)
            ->with('budget.category')
            ->orderByDesc('threshold')
            ->orderBy('id')
            ->get();

        $payloads = $alerts->map(fn (BudgetAlert $alert) => $this->alertPayload($alert))->values();

        return [
            'alerts'  => $payloads->all(),
            'summary' => [
                'total'            => $payloads->count(),
                'unread'           => $payloads->where('is_read', false)->count(),
                'overspent_count'  => $payloads->filter(fn (array $alert) => $alert['percentage_used'] > 100)
                    ->pluck('budget_id')
                    ->unique()
                    ->count(),
                'period_month'     => $month->format('Y-m'),
            ],
        ];
    }

    public function sync(int $userId, CarbonImmutable $month): void
    {
        $period = $month->startOfMonth()->toDateString();
        $budgets = Budget::query()
            ->whereUser($userId)
            ->whereYear('month_year', $month->year)
            ->whereMonth('month_year', $month->month)
            ->get();

        $budgetIds = [];

        foreach ($budgets as $budget) {
            $budgetIds[] = $budget->id;
            $spentCents = $this->spentCents($budget, $month);
            $limitCents = Money::toCents((string) $budget->limit_amount);
            $crossed = [];

            foreach ($this->thresholds($budget) as $threshold) {
                if ($limitCents > 0 && $spentCents * 100 >= $limitCents * $threshold) {
                    $crossed[] = $threshold;
                }
            }

            BudgetAlert::query()
                ->where('user_id', $userId)
                ->where('budget_id', $budget->id)
                ->where('period_month', $period)
                ->whereNotIn('threshold', $crossed)
                ->delete();

            foreach ($crossed as $threshold) {
                BudgetAlert::query()->insertOrIgnore([
                    'user_id'      => $userId,
                    'budget_id'    => $budget->id,
                    'threshold'    => $threshold,
                    'period_month' => $period,
                    'spent_amount' => Money::fromCents($spentCents),
                    'limit_amount' => Money::fromCents($limitCents),
                    'read_at'      => null,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                BudgetAlert::query()
                    ->where('user_id', $userId)
                    ->where('budget_id', $budget->id)
                    ->where('threshold', $threshold)
                    ->where('period_month', $period)
                    ->update([
                        'spent_amount' => Money::fromCents($spentCents),
                        'limit_amount' => Money::fromCents($limitCents),
                        'updated_at'   => now(),
                    ]);
            }
        }

        $stale = BudgetAlert::query()
            ->where('user_id', $userId)
            ->where('period_month', $period);

        if ($budgetIds === []) {
            $stale->delete();
        } else {
            $stale->whereNotIn('budget_id', $budgetIds)->delete();
        }
    }

    public function thresholds(Budget $budget): array
    {
        $raw = $budget->alert_thresholds;

        if (! is_array($raw)) {
            return self::DEFAULT_THRESHOLDS;
        }

        $thresholds = collect($raw)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value >= 1 && $value <= 100)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $thresholds === [] ? self::DEFAULT_THRESHOLDS : $thresholds;
    }

    public function spentCents(Budget $budget, CarbonImmutable $month): int
    {
        $spent = Expense::query()
            ->whereUser($budget->user_id)
            ->where('category_id', $budget->category_id)
            ->whereDate('expense_date', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('expense_date', '<=', $month->endOfMonth()->toDateString())
            ->sum('amount');

        return Money::toCents((string) $spent);
    }

    public function markRead(BudgetAlert $alert): BudgetAlert
    {
        if ($alert->read_at === null) {
            $alert->read_at = now();
            $alert->save();
        }

        return $alert->fresh(['budget.category']);
    }

    public function markAllRead(int $userId, CarbonImmutable $month): int
    {
        return BudgetAlert::query()
            ->whereUser($userId)
            ->where('period_month', $month->startOfMonth()->toDateString())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function alertPayload(BudgetAlert $alert): array
    {
        $spentCents = Money::toCents((string) $alert->spent_amount);
        $limitCents = Money::toCents((string) $alert->limit_amount);
        $percentage = $limitCents > 0
            ? round(($spentCents / $limitCents) * 100, 2)
            : 0.0;
        $categoryName = $alert->budget?->category?->name ?? 'Budget category';
        $spent = $this->displayAmount($spentCents);
        $limit = $this->displayAmount($limitCents);

        return [
            'id'              => $alert->id,
            'budget_id'       => $alert->budget_id,
            'threshold'       => $alert->threshold,
            'period_month'    => $alert->period_month?->format('Y-m'),
            'category'        => [
                'id'   => $alert->budget?->category?->id,
                'name' => $categoryName,
            ],
            'spent_amount'    => Money::fromCents($spentCents),
            'limit_amount'    => Money::fromCents($limitCents),
            'percentage_used' => $percentage,
            'message'         => "{$categoryName} spending is {$spent} against a {$limit} limit; "
                ."the {$alert->threshold}% alert threshold was reached.",
            'is_read'         => $alert->read_at !== null,
            'read_at'         => $alert->read_at?->toIso8601String(),
            'created_at'      => $alert->created_at?->toIso8601String(),
        ];
    }

    private function displayAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
