<?php

namespace App\Services;

use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\Subscription;
use App\Support\FinancialCalendar;
use App\Support\FrequencyCost;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SubscriptionService
{
    public const UPCOMING_DAYS = 60;

    /**
     * @param  array{status?: ?string, link?: ?string, search?: ?string, as_of?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function payloadForUser(int $userId, array $filters = []): array
    {
        $asOf = isset($filters['as_of']) && $filters['as_of'] !== null
            ? FinancialCalendar::parseDate($filters['as_of'], 'as_of')
            : FinancialCalendar::today();

        $payloads = $this->payloads($userId, $filters, $asOf);
        $summary = $this->summarize($payloads, $asOf);

        $upcoming = collect($payloads)
            ->filter(fn (array $item) => $item['counts_toward_totals']
                && $item['next_renewal_date'] !== null
                && $item['next_renewal_date'] <= $asOf->addDays(self::UPCOMING_DAYS)->toDateString())
            ->sortBy(fn (array $item) => $item['next_renewal_date'].'#'.$item['name'])
            ->values()
            ->map(function (array $item) use ($asOf) {
                $item['days_until_renewal'] = (int) $asOf->diffInDays(
                    FinancialCalendar::parseDate($item['next_renewal_date'], 'next_renewal_date'),
                    false
                );

                return $item;
            })
            ->all();

        return [
            'subscriptions' => $payloads,
            'summary' => $summary,
            'upcoming_renewals' => $upcoming,
            'upcoming_window_days' => self::UPCOMING_DAYS,
            'filters' => [
                'status' => $filters['status'] ?? null,
                'link' => $filters['link'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
            'as_of' => $asOf->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
            'notes' => [
                'A linked subscription reads amount, frequency, category and renewal date from its recurring expense schedule.',
                'Each recurring expense can be linked to at most one subscription, so the same obligation is counted once.',
                'Paused or cancelled trackers, and trackers whose linked schedule is paused or stopped, are excluded from cost totals.',
                'Changing a subscription tracker never pauses, cancels or pays the underlying recurring expense.',
                'SpendSense cannot cancel a subscription with a provider; this page is a manual tracker.',
            ],
        ];
    }

    /**
     * Cost-only summary used by the spending insights endpoint.
     *
     * @return array<string, mixed>
     */
    public function summaryForUser(int $userId, ?string $asOf = null): array
    {
        $date = $asOf !== null
            ? FinancialCalendar::parseDate($asOf, 'as_of')
            : FinancialCalendar::today();

        return $this->summarize($this->payloads($userId, [], $date), $date);
    }

    public function create(int $userId, array $data): array
    {
        $subscription = DB::transaction(function () use ($userId, $data) {
            $schedule = null;

            if (isset($data['recurring_expense_id']) && $data['recurring_expense_id'] !== null) {
                $schedule = RecurringExpense::query()
                    ->whereUser($userId)
                    ->whereKey($data['recurring_expense_id'])
                    ->lockForUpdate()
                    ->first();

                if ($schedule === null) {
                    throw ValidationException::withMessages([
                        'recurring_expense_id' => 'The selected recurring expense is not available.',
                    ]);
                }

                if ($schedule->subscription()->exists()) {
                    throw new ConflictHttpException(
                        'This recurring expense already has a subscription tracker.'
                    );
                }
            }

            $categoryId = $data['category_id'] ?? null;
            if ($schedule === null && $categoryId !== null) {
                $this->assertExpenseCategory($userId, (int) $categoryId);
            }

            try {
                return Subscription::create($this->attributesFor($userId, $data, $schedule));
            } catch (UniqueConstraintViolationException $exception) {
                throw new ConflictHttpException(
                    'This recurring expense already has a subscription tracker.'
                );
            }
        });

        return $this->payloadForSubscription($subscription);
    }

    public function update(int $userId, Subscription $subscription, array $data): array
    {
        $updated = DB::transaction(function () use ($userId, $subscription, $data) {
            $locked = Subscription::query()
                ->whereUser($userId)
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isLinked() && array_key_exists('category_id', $data)) {
                if ($data['category_id'] !== null) {
                    $this->assertExpenseCategory($userId, (int) $data['category_id'], (int) $locked->category_id);
                }
            }

            $fields = $locked->isLinked()
                ? ['name', 'provider', 'status', 'notes']
                : ['name', 'provider', 'status', 'notes', 'amount', 'frequency', 'next_renewal_date', 'category_id'];

            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $field === 'amount'
                        ? Money::fromCents(Money::toCents($data[$field]))
                        : $data[$field];
                }
            }

            $locked->save();

            return $locked;
        });

        return $this->payloadForSubscription($updated);
    }

    public function payloadForSubscription(Subscription $subscription, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= FinancialCalendar::today();

        $subscription->loadMissing([
            'schedule.category',
            'schedule.occurrences' => fn ($query) => $query
                ->where('status', 'planned')
                ->orderBy('occurrence_date')
                ->orderBy('id'),
            'category',
        ]);

        return $this->subscriptionPayload($subscription, $asOf);
    }

    private function attributesFor(int $userId, array $data, ?RecurringExpense $schedule): array
    {
        $attributes = [
            'user_id' => $userId,
            'name' => $data['name'],
            'provider' => $data['provider'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
            'recurring_expense_id' => $schedule?->id,
            'amount' => null,
            'frequency' => null,
            'next_renewal_date' => null,
            'category_id' => null,
        ];

        if ($schedule === null) {
            $attributes['amount'] = Money::fromCents(Money::toCents($data['amount']));
            $attributes['frequency'] = $data['frequency'];
            $attributes['next_renewal_date'] = $data['next_renewal_date'];
            $attributes['category_id'] = $data['category_id'] ?? null;
        }

        return $attributes;
    }

    /**
     * @param  array{status?: ?string, link?: ?string, search?: ?string, as_of?: ?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function payloads(int $userId, array $filters, CarbonImmutable $asOf): array
    {
        $query = Subscription::query()
            ->whereUser($userId)
            ->with([
                'schedule.category',
                'schedule.occurrences' => fn ($occurrence) => $occurrence
                    ->where('status', 'planned')
                    ->orderBy('occurrence_date')
                    ->orderBy('id'),
                'category',
            ]);

        $status = $filters['status'] ?? null;
        if ($status !== null && in_array($status, Subscription::STATUSES, true)) {
            $query->where('status', $status);
        }

        $link = $filters['link'] ?? null;
        if ($link === 'linked') {
            $query->whereNotNull('recurring_expense_id');
        } elseif ($link === 'standalone') {
            $query->whereNull('recurring_expense_id');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($nested) use ($needle) {
                $nested->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(provider, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', [$needle])
                    ->orWhereHas('schedule', fn ($schedule) => $schedule
                        ->whereRaw('LOWER(description) LIKE ?', [$needle]));
            });
        }

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Subscription $subscription) => $this->subscriptionPayload($subscription, $asOf))
            ->sortBy(function (array $item) {
                return match ($item['effective_status']) {
                    'active' => '0',
                    'paused' => '1',
                    default => '2',
                }.'#'.($item['next_renewal_date'] ?? '9999-12-31').'#'.mb_strtolower($item['name']);
            })
            ->values()
            ->all();
    }

    private function subscriptionPayload(Subscription $subscription, CarbonImmutable $asOf): array
    {
        $schedule = $subscription->schedule;
        $linked = $subscription->isLinked() && $schedule !== null;
        $scheduleStatus = $schedule?->status;

        $effectiveStatus = match (true) {
            $subscription->status === 'cancelled' => 'cancelled',
            $linked && $scheduleStatus === 'stopped' => 'cancelled',
            $subscription->status === 'paused' => 'paused',
            $linked && $scheduleStatus === 'paused' => 'paused',
            default => 'active',
        };

        $amount = $linked ? (string) $schedule->amount : (string) $subscription->amount;
        $frequency = $linked ? $schedule->frequency : $subscription->frequency;
        $category = $linked ? $schedule->category : $subscription->category;

        $nextRenewal = null;
        if ($linked) {
            $occurrence = $schedule->occurrences->first();
            $nextRenewal = $occurrence?->occurrence_date?->toDateString();
        } else {
            $nextRenewal = $subscription->next_renewal_date?->toDateString();
        }

        $monthlyCents = FrequencyCost::monthlyCents($amount, (string) $frequency);
        $annualCents = FrequencyCost::annualCents($amount, (string) $frequency);
        $counts = $effectiveStatus === 'active';

        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'provider' => $subscription->provider,
            'amount' => Money::fromCents(Money::toCents($amount)),
            'frequency' => $frequency,
            'frequency_label' => FrequencyCost::frequencyLabel((string) $frequency),
            'next_renewal_date' => $nextRenewal,
            'is_overdue' => $nextRenewal !== null
                && $nextRenewal < $asOf->toDateString()
                && $counts,
            'category' => $category === null ? null : [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'status' => $subscription->status,
            'effective_status' => $effectiveStatus,
            'schedule_status' => $scheduleStatus,
            'counts_toward_totals' => $counts,
            'is_linked' => $linked,
            'monthly_cost' => Money::fromCents($monthlyCents),
            'annual_cost' => Money::fromCents($annualCents),
            'notes' => $subscription->notes,
            'recurring_expense_id' => $subscription->recurring_expense_id,
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->id,
                'description' => $schedule->description,
                'status' => $schedule->status,
                'frequency' => $schedule->frequency,
                'amount' => (string) $schedule->amount,
                'next_due_date' => $nextRenewal,
            ],
            'created_at' => $subscription->created_at?->toIso8601String(),
            'updated_at' => $subscription->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function summarize(array $items, CarbonImmutable $asOf): array
    {
        $active = array_values(array_filter(
            $items,
            fn (array $item) => $item['effective_status'] === 'active'
        ));

        $monthlyCents = 0;
        $annualCents = 0;
        $dueSoonCents = 0;
        $overdueCount = 0;
        $dueSoonCount = 0;

        foreach ($active as $item) {
            $monthlyCents += Money::toCents($item['monthly_cost']);
            $annualCents += Money::toCents($item['annual_cost']);
            $date = $item['next_renewal_date'];

            if ($date === null) {
                continue;
            }

            if ($date < $asOf->toDateString()) {
                $overdueCount++;
            } elseif ($date <= $asOf->addDays(30)->toDateString()) {
                $dueSoonCount++;
                $dueSoonCents += Money::toCents($item['amount'] ?? '0');
            }
        }

        $counts = ['active' => 0, 'paused' => 0, 'cancelled' => 0];
        foreach ($items as $item) {
            $counts[$item['effective_status']] = ($counts[$item['effective_status']] ?? 0) + 1;
        }

        return [
            'total_count' => count($items),
            'active_count' => $counts['active'],
            'paused_count' => $counts['paused'],
            'cancelled_count' => $counts['cancelled'],
            'linked_count' => count(array_filter($items, fn (array $item) => $item['is_linked'])),
            'standalone_count' => count(array_filter($items, fn (array $item) => ! $item['is_linked'])),
            'estimated_monthly_cost' => Money::fromCents($monthlyCents),
            'estimated_annual_cost' => Money::fromCents($annualCents),
            'due_within_30_days_count' => $dueSoonCount,
            'due_within_30_days_amount' => Money::fromCents($dueSoonCents),
            'overdue_count' => $overdueCount,
            'as_of' => $asOf->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
        ];
    }

    private function assertExpenseCategory(int $userId, int $categoryId, ?int $allowId = null): void
    {
        $category = Category::query()
            ->availableTo($userId, null, $allowId)
            ->find($categoryId);

        if ($category === null || $category->type !== 'expense') {
            throw ValidationException::withMessages([
                'category_id' => 'The subscription category must be an expense category.',
            ]);
        }
    }
}
