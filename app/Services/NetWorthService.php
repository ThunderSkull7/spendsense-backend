<?php

namespace App\Services;

use App\Models\NetWorthEntry;
use App\Models\NetWorthValuation;
use App\Support\FinancialCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class NetWorthService
{
    /**
     * `as_of` limits which valuation is considered current. Archived entries
     * remain visible for history but never contribute to totals.
     *
     * @return array<string, mixed>
     */
    public function index(int $userId, string $status = 'active', ?string $asOf = null): array
    {
        $date = $asOf !== null
            ? FinancialCalendar::parseDate($asOf, 'as_of')
            : FinancialCalendar::today();

        $entries = $this->entriesQuery($userId, $date)->get();
        $active = $entries->where('status', 'active');
        $visible = $status === 'all'
            ? $entries
            : $entries->where('status', $status);

        $payloads = $visible
            ->sortBy(fn (NetWorthEntry $entry) => ($entry->status === 'active' ? '0' : '1')
                .'#'.mb_strtolower($entry->name))
            ->values()
            ->map(fn (NetWorthEntry $entry) => $this->entryPayload($entry))
            ->all();

        return [
            'entries' => $payloads,
            'summary' => $this->summary($active, $date),
            'filters' => ['status' => $status],
            'as_of' => $date->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
            'notes' => [
                'Values are user-entered estimates, not live bank or market prices.',
                'Assets and liabilities are positive amounts; net worth subtracts liabilities from assets.',
                'Archived entries keep their valuation history but are excluded from current totals and the trend.',
                'Savings goals are earmarks, not separate cash. Record the same bank balance only once to avoid double-counting.',
                'Net worth is not the same as cash available for spending.',
                'Recording or changing a valuation never creates income or expense transactions.',
            ],
        ];
    }

    public function show(int $userId, NetWorthEntry $entry, ?string $asOf = null): array
    {
        $date = $asOf !== null
            ? FinancialCalendar::parseDate($asOf, 'as_of')
            : FinancialCalendar::today();

        if ($entry->user_id !== $userId) {
            abort(403);
        }

        $entry->load(['valuations' => fn ($query) => $query
            ->whereDate('valuation_date', '<=', $date->toDateString())
            ->orderByDesc('valuation_date')
            ->orderByDesc('id')]);
        $entry->loadCount('valuations');

        return $this->entryPayload($entry, true);
    }

    public function create(int $userId, array $data): array
    {
        $entry = DB::transaction(function () use ($userId, $data) {
            $this->assertCategory($data['type'], $data['category']);

            $entry = NetWorthEntry::create([
                'user_id' => $userId,
                'name' => $data['name'],
                'type' => $data['type'],
                'category' => $data['category'],
                'institution' => $data['institution'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'active',
            ]);

            $date = $data['valuation_date'] ?? FinancialCalendar::today()->toDateString();
            $this->assertNotFuture($date);

            NetWorthValuation::create([
                'net_worth_entry_id' => $entry->id,
                'value_amount' => Money::fromCents(Money::toCents($data['value'])),
                'valuation_date' => $date,
                'note' => $data['valuation_note'] ?? 'Opening valuation',
            ]);

            return $entry;
        });

        return $this->payloadForEntry($entry->fresh());
    }

    public function update(int $userId, NetWorthEntry $entry, array $data): array
    {
        $updated = DB::transaction(function () use ($userId, $entry, $data) {
            $locked = NetWorthEntry::query()
                ->whereUser($userId)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'archived') {
                throw new ConflictHttpException('Archived entries are read-only. Restore the entry before editing it.');
            }

            $type = $data['type'] ?? $locked->type;
            $category = $data['category'] ?? $locked->category;
            $this->assertCategory($type, $category);

            foreach (['name', 'type', 'category', 'institution', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            $locked->save();

            return $locked;
        });

        return $this->payloadForEntry($updated->fresh());
    }

    public function archive(int $userId, NetWorthEntry $entry): array
    {
        $updated = DB::transaction(function () use ($userId, $entry) {
            $locked = NetWorthEntry::query()
                ->whereUser($userId)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'archived') {
                $locked->status = 'archived';
                $locked->archived_at = now();
                $locked->save();
            }

            return $locked;
        });

        return $this->payloadForEntry($updated->fresh());
    }

    public function restore(int $userId, NetWorthEntry $entry): array
    {
        $updated = DB::transaction(function () use ($userId, $entry) {
            $locked = NetWorthEntry::query()
                ->whereUser($userId)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'archived') {
                throw new ConflictHttpException('Only an archived entry can be restored.');
            }

            $locked->status = 'active';
            $locked->archived_at = null;
            $locked->save();

            return $locked;
        });

        return $this->payloadForEntry($updated->fresh());
    }

    public function addValuation(int $userId, NetWorthEntry $entry, array $data): array
    {
        $date = FinancialCalendar::parseDate($data['valuation_date'], 'valuation_date');
        $this->assertNotFuture($date->toDateString());

        DB::transaction(function () use ($userId, $entry, $data, $date) {
            $locked = NetWorthEntry::query()
                ->whereUser($userId)
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'archived') {
                throw new ConflictHttpException('Archived entries are read-only. Restore the entry before adding a valuation.');
            }

            $exists = NetWorthValuation::query()
                ->where('net_worth_entry_id', $locked->id)
                ->whereDate('valuation_date', $date->toDateString())
                ->exists();

            if ($exists) {
                throw new ConflictHttpException(
                    'A valuation already exists for this entry and date. Keep the existing snapshot and record changes on a later date.'
                );
            }

            try {
                NetWorthValuation::create([
                    'net_worth_entry_id' => $locked->id,
                    'value_amount' => Money::fromCents(Money::toCents($data['value'])),
                    'valuation_date' => $date->toDateString(),
                    'note' => $data['note'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new ConflictHttpException(
                    'A valuation already exists for this entry and date.'
                );
            }
        });

        return $this->show($userId, $entry->fresh(), null);
    }

    /**
     * Month-end trend for currently active entries. Loads the active entries
     * and their snapshots in two queries, then computes each month-end value in
     * memory without querying per entry or per month.
     *
     * @return array<string, mixed>
     */
    public function trend(int $userId, int $months = 12, ?string $asOf = null): array
    {
        if ($months < 2 || $months > 24) {
            throw ValidationException::withMessages([
                'months' => 'Choose between 2 and 24 months of history.',
            ]);
        }

        $date = $asOf !== null
            ? FinancialCalendar::parseDate($asOf, 'as_of')
            : FinancialCalendar::today();
        $start = $date->startOfMonth()->subMonthsNoOverflow($months - 1);

        $entries = NetWorthEntry::query()
            ->whereUser($userId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'type']);

        $entryTypes = $entries->pluck('type', 'id')->all();
        $valuations = NetWorthValuation::query()
            ->whereIn('net_worth_entry_id', array_keys($entryTypes))
            ->whereDate('valuation_date', '<=', $date->toDateString())
            ->orderBy('valuation_date')
            ->orderBy('id')
            ->get(['net_worth_entry_id', 'value_amount', 'valuation_date'])
            ->groupBy('net_worth_entry_id');

        $latest = [];
        $cursor = [];
        $points = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $month = $start->addMonthsNoOverflow($offset);
            $monthEnd = $month->endOfMonth()->gt($date) ? $date : $month->endOfMonth();
            $assets = 0;
            $liabilities = 0;
            $valuedEntries = 0;

            foreach ($entryTypes as $entryId => $type) {
                $rows = $valuations->get($entryId, collect());
                $index = $cursor[$entryId] ?? 0;

                while ($index < $rows->count()) {
                    $row = $rows[$index];
                    $rowDate = $row->valuation_date?->toDateString()
                        ?? substr((string) $row->valuation_date, 0, 10);

                    if ($rowDate > $monthEnd->toDateString()) {
                        break;
                    }

                    $latest[$entryId] = $row;
                    $index++;
                }

                $cursor[$entryId] = $index;

                if (! isset($latest[$entryId])) {
                    continue;
                }

                $valuedEntries++;
                $cents = Money::toCents((string) $latest[$entryId]->value_amount);
                if ($type === 'liability') {
                    $liabilities += $cents;
                } else {
                    $assets += $cents;
                }
            }

            if ($valuedEntries === 0) {
                continue;
            }

            $points[] = [
                'month' => $month->format('Y-m'),
                'as_of' => $monthEnd->toDateString(),
                'total_assets' => Money::fromCents($assets),
                'total_liabilities' => Money::fromCents($liabilities),
                'net_worth' => Money::fromCents($assets - $liabilities),
                'valued_entries' => $valuedEntries,
                'is_current_month' => $month->format('Y-m') === $date->format('Y-m'),
            ];
        }

        return [
            'points' => $points,
            'months' => $months,
            'as_of' => $date->toDateString(),
            'sufficient_history' => count($points) >= 2,
            'active_entry_count' => count($entryTypes),
            'timezone' => FinancialCalendar::TIMEZONE,
            'notes' => [
                'Each point uses the latest valuation on or before that month-end for currently active entries.',
                'Entries archived today are excluded from the whole trend for a like-for-like current view.',
                'A trend is available after snapshots exist in at least two months.',
            ],
        ];
    }

    public function payloadForEntry(NetWorthEntry $entry, bool $withHistory = false): array
    {
        if ($entry->valuations()->exists() && ! $entry->relationLoaded('valuations')) {
            $entry->load(['valuations' => fn ($query) => $query
                ->orderByDesc('valuation_date')
                ->orderByDesc('id')]);
        }
        if (! isset($entry->valuations_count)) {
            $entry->loadCount('valuations');
        }

        return $this->entryPayload($entry, $withHistory);
    }

    private function entriesQuery(int $userId, CarbonImmutable $asOf)
    {
        return NetWorthEntry::query()
            ->whereUser($userId)
            ->withCount('valuations')
            ->addSelect([
                'latest_value' => NetWorthValuation::query()
                    ->select('value_amount')
                    ->whereColumn('net_worth_entry_id', 'net_worth_entries.id')
                    ->whereDate('valuation_date', '<=', $asOf->toDateString())
                    ->orderByDesc('valuation_date')
                    ->orderByDesc('id')
                    ->limit(1),
                'latest_valuation_date' => NetWorthValuation::query()
                    ->select('valuation_date')
                    ->whereColumn('net_worth_entry_id', 'net_worth_entries.id')
                    ->whereDate('valuation_date', '<=', $asOf->toDateString())
                    ->orderByDesc('valuation_date')
                    ->orderByDesc('id')
                    ->limit(1),
            ]);
    }

    /**
     * @param  Collection<int, NetWorthEntry>  $active
     * @return array<string, mixed>
     */
    private function summary($active, CarbonImmutable $asOf): array
    {
        $assets = 0;
        $liabilities = 0;
        $unvalued = 0;
        $categoryTotals = [];

        foreach ($active as $entry) {
            if ($entry->latest_value === null) {
                $unvalued++;

                continue;
            }

            $cents = Money::toCents((string) $entry->latest_value);
            $key = $entry->type.'|'.$entry->category;
            if (! isset($categoryTotals[$key])) {
                $categoryTotals[$key] = [
                    'type' => $entry->type,
                    'category' => $entry->category,
                    'category_label' => NetWorthEntry::categoryLabel($entry->category),
                    'total' => 0,
                    'entry_count' => 0,
                ];
            }
            $categoryTotals[$key]['total'] += $cents;
            $categoryTotals[$key]['entry_count']++;

            if ($entry->type === 'liability') {
                $liabilities += $cents;
            } else {
                $assets += $cents;
            }
        }

        $categories = collect($categoryTotals)
            ->sortByDesc('total')
            ->map(function (array $row) {
                $row['total'] = Money::fromCents($row['total']);

                return $row;
            })
            ->values()
            ->all();

        return [
            'total_assets' => Money::fromCents($assets),
            'total_liabilities' => Money::fromCents($liabilities),
            'net_worth' => Money::fromCents($assets - $liabilities),
            'active_entry_count' => $active->count(),
            'unvalued_entry_count' => $unvalued,
            'has_valuation' => $assets + $liabilities > 0 || ($active->count() > $unvalued),
            'category_totals' => $categories,
            'as_of' => $asOf->toDateString(),
            'timezone' => FinancialCalendar::TIMEZONE,
        ];
    }

    private function entryPayload(NetWorthEntry $entry, bool $withHistory = false): array
    {
        $latestValue = $entry->latest_value;
        $latestDate = $entry->latest_valuation_date;
        $history = $entry->relationLoaded('valuations') ? $entry->valuations : collect();

        if ($latestValue === null && $history->isNotEmpty()) {
            $latestValue = $history->first()->value_amount;
            $latestDate = $history->first()->valuation_date;
        }

        $dateString = $latestDate instanceof \DateTimeInterface
            ? CarbonImmutable::instance($latestDate)->toDateString()
            : ($latestDate === null ? null : substr((string) $latestDate, 0, 10));

        $payload = [
            'id' => $entry->id,
            'name' => $entry->name,
            'type' => $entry->type,
            'category' => $entry->category,
            'category_label' => NetWorthEntry::categoryLabel($entry->category),
            'institution' => $entry->institution,
            'notes' => $entry->notes,
            'status' => $entry->status,
            'latest_value' => $latestValue === null ? null : Money::fromCents(Money::toCents((string) $latestValue)),
            'latest_valuation_date' => $dateString,
            'valuation_count' => (int) ($entry->valuations_count ?? $history->count()),
            'archived_at' => $entry->archived_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];

        if ($withHistory) {
            $payload['valuations'] = $history
                ->sortByDesc(fn (NetWorthValuation $valuation) => $valuation->valuation_date->toDateString().'#'.$valuation->id)
                ->values()
                ->map(fn (NetWorthValuation $valuation) => [
                    'id' => $valuation->id,
                    'value' => Money::fromCents(Money::toCents((string) $valuation->value_amount)),
                    'valuation_date' => $valuation->valuation_date->toDateString(),
                    'note' => $valuation->note,
                    'created_at' => $valuation->created_at?->toIso8601String(),
                ])
                ->all();
        }

        return $payload;
    }

    private function assertCategory(string $type, string $category): void
    {
        if (! in_array($type, NetWorthEntry::TYPES, true)
            || ! in_array($category, NetWorthEntry::categoriesFor($type), true)) {
            throw ValidationException::withMessages([
                'category' => 'The selected category does not belong to this entry type.',
            ]);
        }
    }

    private function assertNotFuture(string $date): void
    {
        if ($date > FinancialCalendar::today()->toDateString()) {
            throw ValidationException::withMessages([
                'valuation_date' => 'Valuation dates cannot be in the future.',
            ]);
        }
    }
}
