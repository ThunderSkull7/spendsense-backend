<?php

namespace App\Services;

use App\Support\Csv;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionSearchService
{
    public const MAX_EXPORT_ROWS = 50000;

    /**
     * Expense and income rows are unioned only for search/export. Their
     * separate ledger endpoints remain the source of truth for edits.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(int $userId, array $filters): Builder
    {
        $type = $filters['type'] ?? 'all';
        $includeExpenses = $type !== 'income';
        $includeIncomes = $type !== 'expense';

        $expenses = DB::table('expenses as e')
            ->leftJoin('categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.user_id', $userId)
            ->whereNull('e.deleted_at')
            ->select([
                DB::raw("'expense' as type"),
                'e.id as id',
                'e.description as description',
                'e.amount as amount',
                'e.expense_date as transaction_date',
                'e.category_id as category_id',
                'c.name as category_name',
                DB::raw('NULL as source'),
                DB::raw('NULL as notes'),
                'e.created_at as created_at',
            ]);

        $incomes = DB::table('incomes as i')
            ->where('i.user_id', $userId)
            ->whereNull('i.deleted_at')
            ->select([
                DB::raw("'income' as type"),
                'i.id as id',
                'i.source as description',
                'i.amount as amount',
                'i.income_date as transaction_date',
                DB::raw('NULL as category_id'),
                DB::raw('NULL as category_name'),
                'i.source as source',
                'i.notes as notes',
                'i.created_at as created_at',
            ]);

        $this->applyFilters($expenses, $filters, 'expense');
        $this->applyFilters($incomes, $filters, 'income');

        if (($filters['category_id'] ?? null) !== null) {
            // Income has no category in the Stage 1 schema. A category filter
            // therefore restricts the union to matching expenses and does not
            // invent an income category.
            $incomes->whereRaw('1 = 0');
        }

        if ($includeExpenses && $includeIncomes) {
            $union = $expenses->unionAll($incomes);
        } elseif ($includeExpenses) {
            $union = $expenses;
        } else {
            $union = $incomes;
        }

        $query = DB::query()->fromSub($union, 'transactions');

        return $this->applySort($query, $filters['sort'] ?? 'date_desc');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $userId, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->query($userId, $filters)->paginate($perPage);
        $paginator->through(fn (object $row): array => $this->present($row));

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return iterable<int, array<int, string>>
     */
    public function exportRows(int $userId, array $filters): iterable
    {
        $query = $this->query($userId, $filters);
        $total = (int) (clone $query)->count();

        if ($total > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages([
                'export' => 'This export contains more than '.number_format(self::MAX_EXPORT_ROWS).' transactions. Narrow the date range or filters.',
            ]);
        }

        yield [
            'Type',
            'Date',
            'Description',
            'Category / source',
            'Notes',
            'Amount',
            'Signed amount',
        ];

        foreach ($query->cursor() as $row) {
            $presented = $this->present((object) $row);

            $amount = Money::toCents((string) $row->amount) / 100;
            $signed = $row->type === 'expense' ? -$amount : $amount;

            yield [
                Csv::cell($presented['type']),
                Csv::cell($presented['transaction_date']),
                Csv::cell($presented['description']),
                Csv::cell($presented['category_name'] ?? $presented['source'] ?? ''),
                Csv::cell($presented['notes'] ?? ''),
                Csv::cell(number_format($amount, 2, '.', '')),
                Csv::cell(number_format($signed, 2, '.', '')),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        return [
            'type' => $row->type,
            'id' => (int) $row->id,
            'description' => $row->description,
            // The query builder returns the raw driver value, so the amount is
            // normalised to the same two-decimal string the rest of the API
            // uses. This keeps the display, the export and the totals equal.
            'amount' => Money::fromCents(Money::toCents((string) $row->amount)),
            'transaction_date' => substr((string) $row->transaction_date, 0, 10),
            'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
            'category_name' => $row->category_name,
            'source' => $row->source,
            'notes' => $row->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $ledger): void
    {
        $dateColumn = $ledger === 'expense' ? 'e.expense_date' : 'i.income_date';

        if (! empty($filters['from'])) {
            $query->whereDate($dateColumn, '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate($dateColumn, '<=', $filters['to']);
        }

        if (isset($filters['min_amount']) && $filters['min_amount'] !== null) {
            $query->where($ledger === 'expense' ? 'e.amount' : 'i.amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount']) && $filters['max_amount'] !== null) {
            $query->where($ledger === 'expense' ? 'e.amount' : 'i.amount', '<=', $filters['max_amount']);
        }

        if (($filters['category_id'] ?? null) !== null && $ledger === 'expense') {
            $query->where('e.category_id', (int) $filters['category_id']);
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search === '') {
            return;
        }

        $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';

        if ($ledger === 'expense') {
            $query->whereRaw("LOWER(COALESCE(e.description, '')) LIKE ? ESCAPE '!'", [$like]);

            return;
        }

        $query->where(function (Builder $nested) use ($like): void {
            $nested->whereRaw("LOWER(COALESCE(i.source, '')) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(COALESCE(i.notes, '')) LIKE ? ESCAPE '!'", [$like]);
        });
    }

    /**
     * Escape the LIKE metacharacters with the same character the query uses
     * in its ESCAPE clause.
     *
     * The escape character is "!" rather than a backslash on purpose: a
     * backslash inside a quoted escape literal is not portable between
     * SQLite and PostgreSQL, and PostgreSQL rejects a prepared statement
     * whose escape literal contains one.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        $direction = $sort === 'date_asc' ? 'asc' : 'desc';

        return match ($sort) {
            'amount_asc' => $query
                ->orderBy('amount', 'asc')
                ->orderBy('transaction_date', 'desc')
                ->orderBy('type', 'asc')
                ->orderBy('id', 'desc'),
            'amount_desc' => $query
                ->orderBy('amount', 'desc')
                ->orderBy('transaction_date', 'desc')
                ->orderBy('type', 'asc')
                ->orderBy('id', 'desc'),
            default => $query
                ->orderBy('transaction_date', $direction)
                ->orderBy('created_at', $direction)
                ->orderBy('type', 'asc')
                ->orderBy('id', $direction),
        };
    }
}
