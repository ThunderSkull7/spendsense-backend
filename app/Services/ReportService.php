<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpenseOccurrence;
use App\Support\DateExpressions;
use App\Support\FinancialCalendar;
use App\Support\Money;
use App\Support\ReportSections;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function __construct(
        private readonly BudgetPerformanceService $budgets,
        private readonly SavingsProgressService $savings,
        private readonly CommitmentService $commitments,
        private readonly CashFlowForecastService $forecast,
        private readonly NetWorthService $netWorth,
        private readonly SubscriptionService $subscriptions
    ) {}

    /**
     * One report contract powers the preview and CSV export. All actual totals
     * are date-bounded, soft-delete aware and calculated in integer cents.
     *
     * @param  list<string>|null  $sections
     * @return array<string, mixed>
     */
    public function build(
        int $userId,
        ?string $fromDate = null,
        ?string $toDate = null,
        string $compare = 'previous',
        ?array $sections = null
    ): array {
        if (! in_array($compare, ['previous', 'none'], true)) {
            throw ValidationException::withMessages([
                'compare' => 'Choose a previous-period comparison or no comparison.',
            ]);
        }

        [$from, $to] = $this->resolveDates($fromDate, $toDate);
        $days = $from->diffInDays($to) + 1;

        if ($days > 731) {
            throw ValidationException::withMessages([
                'to' => 'Reports cover at most about two years at a time.',
            ]);
        }

        $requested = $sections === null ? ReportSections::ALL : array_values(array_intersect(ReportSections::ALL, $sections));
        $has = fn (string $section): bool => in_array($section, $requested, true);

        $incomeCents = $this->total($userId, 'incomes', 'income_date', $from->toDateString(), $to->toDateString());
        $expenseCents = $this->total($userId, 'expenses', 'expense_date', $from->toDateString(), $to->toDateString());
        $incomeCount = $this->count($userId, 'incomes', 'income_date', $from->toDateString(), $to->toDateString());
        $expenseCount = $this->count($userId, 'expenses', 'expense_date', $from->toDateString(), $to->toDateString());

        $previous = null;
        $previousFrom = null;
        $previousTo = null;
        $comparison = null;

        if ($compare === 'previous') {
            [$previousFrom, $previousTo] = $this->previousPeriod($from, $to);
            $previousIncomeCents = $this->total($userId, 'incomes', 'income_date', $previousFrom->toDateString(), $previousTo->toDateString());
            $previousExpenseCents = $this->total($userId, 'expenses', 'expense_date', $previousFrom->toDateString(), $previousTo->toDateString());
            $previous = [
                'income_amount' => Money::fromCents($previousIncomeCents),
                'expense_amount' => Money::fromCents($previousExpenseCents),
                'net_amount' => Money::fromCents($previousIncomeCents - $previousExpenseCents),
                'income_count' => $this->count($userId, 'incomes', 'income_date', $previousFrom->toDateString(), $previousTo->toDateString()),
                'expense_count' => $this->count($userId, 'expenses', 'expense_date', $previousFrom->toDateString(), $previousTo->toDateString()),
            ];
        }

        $needCategories = $has('categories') || $compare === 'previous';
        $needSources = $has('income_sources') || $compare === 'previous';
        $categories = $needCategories
            ? $this->expenseCategories($userId, $from->toDateString(), $to->toDateString())
            : [];
        $sources = $needSources
            ? $this->incomeSources($userId, $from->toDateString(), $to->toDateString())
            : [];

        if ($compare === 'previous') {
            $previousCategories = $needCategories
                ? $this->expenseCategories($userId, $previousFrom->toDateString(), $previousTo->toDateString())
                : [];
            $previousSources = $needSources
                ? $this->incomeSources($userId, $previousFrom->toDateString(), $previousTo->toDateString())
                : [];
            $comparison = $this->comparisonPayload(
                $incomeCents,
                $expenseCents,
                $previousIncomeCents,
                $previousExpenseCents,
                $categories,
                $previousCategories,
                $sources,
                $previousSources
            );
        }

        $budgets = $has('budgets')
            ? $this->budgets->forUser($userId, $from->format('Y-m'), $to->format('Y-m'))
            : null;
        $savings = $has('savings')
            ? $this->savings->forUser($userId, $from->format('Y-m'), $to->format('Y-m'))
            : null;

        $needCommitments = $has('commitments') || $has('upcoming_bills') || $has('subscriptions');
        $commitmentData = $needCommitments
            ? $this->commitments->forUser($userId, $from->format('Y-m'), $to->format('Y-m'), FinancialCalendar::today()->toDateString())
            : null;

        $upcomingBills = null;
        $subscriptionData = null;

        if ($commitmentData !== null) {
            if ($has('upcoming_bills')) {
                $upcomingBills = array_values(array_filter(
                    $commitmentData['upcoming_obligations'],
                    fn (array $row): bool => $row['source'] === 'bill'
                ));
                $upcomingBills = array_slice($upcomingBills, 0, 20);
            }

            if ($has('subscriptions')) {
                $subscriptionData = [
                    'summary' => $commitmentData['subscription_breakdown']['summary'],
                    'standalone' => array_slice($commitmentData['subscription_breakdown']['standalone'], 0, 20),
                    'linked' => array_slice($commitmentData['subscription_breakdown']['linked'], 0, 20),
                    'notes' => [
                        'Subscription estimates are separate from recorded expenses.',
                        'A linked subscription is represented by its recurring bill schedule and is not counted twice in the combined commitment totals.',
                    ],
                ];
            }
        }

        $forecast = $has('forecast')
            ? $this->forecast->forUser($userId, 30, FinancialCalendar::today()->toDateString())
            : null;
        $netWorth = $has('net_worth')
            ? $this->netWorth->index(
                $userId,
                'active',
                $to->lt(FinancialCalendar::today()) ? $to->toDateString() : FinancialCalendar::today()->toDateString()
            )
            : null;
        $expenseReview = $has('expense_review')
            ? $this->expenseReview($userId, $from->toDateString(), $to->toDateString())
            : null;
        $monthly = $has('monthly')
            ? $this->monthlyActuals($userId, $from, $to)
            : null;

        $expectationSummary = $this->expectations(
            $userId,
            $toDate === null ? FinancialCalendar::today() : $to,
            $commitmentData
        );

        $payload = [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('M j, Y').' – '.$to->format('M j, Y'),
                'days' => $days,
                'timezone' => FinancialCalendar::TIMEZONE,
                'boundary' => 'Inclusive UTC calendar days',
            ],
            'comparison_period' => $previousFrom === null ? null : [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'label' => $previousFrom->format('M j, Y').' – '.$previousTo->format('M j, Y'),
                'basis' => $this->comparisonBasis($from, $to),
            ],
            'sections' => $requested,
            'summary' => [
                'income_amount' => Money::fromCents($incomeCents),
                'expense_amount' => Money::fromCents($expenseCents),
                'net_amount' => Money::fromCents($incomeCents - $expenseCents),
                'income_count' => $incomeCount,
                'expense_count' => $expenseCount,
                'previous' => $previous,
                'changes' => $previous === null ? null : $this->changes($incomeCents, $expenseCents, $previousIncomeCents, $previousExpenseCents),
                'has_actual_data' => $incomeCents > 0 || $expenseCents > 0,
            ],
            'balances' => [
                'opening' => [
                    'status' => 'not_available',
                    'amount' => null,
                    'reason' => 'SpendSense does not store a verified cash-account balance. A net cash-flow report cannot derive an opening bank balance from recorded income and expenses alone.',
                ],
                'ending' => [
                    'status' => 'not_available',
                    'amount' => null,
                    'reason' => 'An ending balance needs a verified opening balance. Only actual income, expenses and net cash-flow totals are reported.',
                ],
            ],
            'expectations' => $expectationSummary,
            'comparison' => $has('comparison') ? $comparison : null,
            'monthly' => $monthly,
            'income_sources' => $has('income_sources') ? $sources : null,
            'categories' => $has('categories') ? $categories : null,
            'budgets' => $budgets,
            'savings' => $savings,
            'commitments' => $has('commitments') && $commitmentData !== null ? [
                'summary' => $commitmentData['summary'],
                'schedules' => array_slice($commitmentData['schedules'], 0, 50),
                'history' => $commitmentData['history'],
                'notes' => $commitmentData['notes'],
            ] : null,
            'upcoming_bills' => $upcomingBills,
            'subscriptions' => $subscriptionData,
            'forecast' => $forecast === null ? null : [
                'as_of' => $forecast['as_of'],
                'window' => $forecast['window'],
                'expected_income' => $forecast['expected_income'],
                'expected_expenses' => $forecast['expected_expenses'],
                'projected_net_cash_flow' => $forecast['projected_net_cash_flow'],
                'expected_income_count' => $forecast['expected_income_count'],
                'expected_expense_count' => $forecast['expected_expense_count'],
                'monthly_projection' => $forecast['monthly_projection'],
                'overdue_commitments' => $forecast['overdue_commitments'],
                'negative_cash_flow_status' => $forecast['negative_cash_flow_status'],
                'negative_cash_flow_reason' => $forecast['negative_cash_flow_reason'],
                'starting_balance' => $forecast['starting_balance'],
                'starting_balance_status' => $forecast['starting_balance_status'],
                'starting_balance_reason' => $forecast['starting_balance_reason'],
            ],
            'net_worth' => $netWorth === null ? null : [
                'as_of' => $netWorth['summary']['as_of'] ?? null,
                'summary' => $netWorth['summary'],
                'entries' => array_slice($netWorth['entries'], 0, 20),
                'note' => 'Net worth uses manual dated valuations. It is not a verified available-cash balance and does not change actual income or expense totals.',
            ],
            'expense_review' => $expenseReview,
            'notes' => [
                'Actual transactions are separated from expected recurring obligations and subscription estimates. Expected amounts are never added to actual income or expense totals.',
                'Soft-deleted expenses and incomes are excluded from all actual report totals.',
                'Budget, savings and commitment sections use whole calendar months covering the selected period; a partial custom date range can therefore show a different basis in those sections.',
                'Savings contributions are internal tracking entries and are not income or expenses.',
                'Linked subscriptions and recurring bill schedules are shown once in combined commitment totals.',
                'No opening or ending bank balance is inferred.',
            ],
        ];

        return $payload;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDates(?string $fromDate, ?string $toDate): array
    {
        if ($fromDate === null && $toDate === null) {
            $today = FinancialCalendar::today();

            return [$today->startOfMonth(), $today];
        }

        if ($fromDate !== null && $toDate === null) {
            $to = FinancialCalendar::today();
            $from = FinancialCalendar::parseDate($fromDate, 'from');

            if ($from->gt($to)) {
                throw ValidationException::withMessages(['to' => 'Choose an end date on or after the start date.']);
            }

            return [$from, $to];
        }

        if ($fromDate === null) {
            $to = FinancialCalendar::parseDate((string) $toDate, 'to');

            return [$to->startOfMonth(), $to];
        }

        $from = FinancialCalendar::parseDate($fromDate, 'from');
        $to = FinancialCalendar::parseDate((string) $toDate, 'to');

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        return [$from, $to];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $quarterStartMonth = (intdiv($from->month - 1, 3) * 3) + 1;
        $isFullMonth = $from->day === 1
            && $to->isEndOfMonth()
            && $from->isSameMonth($to);
        $isFullQuarter = $from->day === 1
            && $from->month === $quarterStartMonth
            && $to->isEndOfMonth()
            && $to->month === $quarterStartMonth + 2
            && $to->year === $from->year;

        if ($isFullMonth) {
            return [
                $from->subMonthNoOverflow()->startOfMonth(),
                $from->subDay()->endOfMonth(),
                'previous_calendar_month',
            ];
        }

        if ($isFullQuarter) {
            return [
                $from->subMonthsNoOverflow(3)->startOfMonth(),
                $from->subDay()->endOfMonth(),
                'previous_calendar_quarter',
            ];
        }

        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($from->diffInDays($to));

        return [$previousFrom, $previousTo, 'equal_length_period'];
    }

    private function comparisonBasis(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $this->previousPeriod($from, $to)[2];
    }

    private function total(int $userId, string $table, string $dateColumn, string $from, string $to): int
    {
        return Money::toCents((string) DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to)
            ->sum('amount'));
    }

    private function count(int $userId, string $table, string $dateColumn, string $from, string $to): int
    {
        return (int) DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $to)
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expenseCategories(int $userId, string $from, string $to): array
    {
        $rows = DB::table('expenses as e')
            ->leftJoin('categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.user_id', $userId)
            ->whereNull('e.deleted_at')
            ->whereDate('e.expense_date', '>=', $from)->whereDate('e.expense_date', '<=', $to)
            ->selectRaw('e.category_id, COALESCE(c.name, ?) as category_name, SUM(e.amount) as total, COUNT(*) as row_count', ['Deleted category'])
            ->groupBy('e.category_id', 'c.name')
            ->orderByDesc('total')
            ->get();

        $totalCents = (int) $rows->sum(fn (object $row): int => Money::toCents((string) $row->total));

        return $rows->map(fn (object $row): array => [
            'category_id' => (int) $row->category_id,
            'category_name' => (string) $row->category_name,
            'amount' => Money::fromCents(Money::toCents((string) $row->total)),
            'transaction_count' => (int) $row->row_count,
            'share_percent' => $totalCents > 0
                ? round((Money::toCents((string) $row->total) / $totalCents) * 100, 1)
                : null,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function incomeSources(int $userId, string $from, string $to): array
    {
        $rows = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from)->whereDate('income_date', '<=', $to)
            ->selectRaw('source, SUM(amount) as total, COUNT(*) as row_count')
            ->groupBy('source')
            ->orderByDesc('total')
            ->get();

        $totalCents = (int) $rows->sum(fn (object $row): int => Money::toCents((string) $row->total));

        return $rows->map(fn (object $row): array => [
            'source' => (string) $row->source,
            'amount' => Money::fromCents(Money::toCents((string) $row->total)),
            'transaction_count' => (int) $row->row_count,
            'share_percent' => $totalCents > 0
                ? round((Money::toCents((string) $row->total) / $totalCents) * 100, 1)
                : null,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $previousCategories
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $previousSources
     * @return array<string, mixed>
     */
    private function comparisonPayload(
        int $income,
        int $expense,
        int $previousIncome,
        int $previousExpense,
        array $categories,
        array $previousCategories,
        array $sources,
        array $previousSources
    ): array {
        $categoryChanges = $this->mergeChangeRows($categories, $previousCategories, 'category_id', 'category_name', 'amount', 'previous_amount');
        $sourceChanges = $this->mergeChangeRows($sources, $previousSources, 'source', 'source', 'amount', 'previous_amount');

        usort($categoryChanges, fn (array $a, array $b): int => abs(Money::toCents($b['change_amount'])) <=> abs(Money::toCents($a['change_amount'])));
        usort($sourceChanges, fn (array $a, array $b): int => abs(Money::toCents($b['change_amount'])) <=> abs(Money::toCents($a['change_amount'])));

        return [
            'current' => [
                'income_amount' => Money::fromCents($income),
                'expense_amount' => Money::fromCents($expense),
                'net_amount' => Money::fromCents($income - $expense),
            ],
            'previous' => [
                'income_amount' => Money::fromCents($previousIncome),
                'expense_amount' => Money::fromCents($previousExpense),
                'net_amount' => Money::fromCents($previousIncome - $previousExpense),
            ],
            'changes' => $this->changes($income, $expense, $previousIncome, $previousExpense),
            'drivers' => [
                'expense_categories' => array_slice($categoryChanges, 0, 5),
                'income_sources' => array_slice($sourceChanges, 0, 5),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $previous
     * @return list<array<string, mixed>>
     */
    private function mergeChangeRows(
        array $current,
        array $previous,
        string $key,
        string $labelKey,
        string $currentAmountKey,
        string $previousAmountKey
    ): array {
        $currentByKey = collect($current)->keyBy($key);
        $previousByKey = collect($previous)->keyBy($key);
        $keys = $currentByKey->keys()->merge($previousByKey->keys())->unique();
        $rows = [];

        foreach ($keys as $value) {
            $currentRow = $currentByKey->get($value);
            $previousRow = $previousByKey->get($value);
            $currentCents = Money::toCents((string) ($currentRow[$currentAmountKey] ?? 0));
            $previousCents = Money::toCents((string) ($previousRow[$previousAmountKey] ?? 0));
            $delta = $currentCents - $previousCents;

            $rows[] = [
                $key => $value,
                $labelKey => $currentRow[$labelKey] ?? $previousRow[$labelKey] ?? 'Unknown',
                'current_amount' => Money::fromCents($currentCents),
                'previous_amount' => Money::fromCents($previousCents),
                'change_amount' => Money::fromCents($delta),
                'change_percent' => $previousCents > 0 ? round(($delta / $previousCents) * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(int $income, int $expense, int $previousIncome, int $previousExpense): array
    {
        return [
            'income' => $this->change($income, $previousIncome),
            'expense' => $this->change($expense, $previousExpense),
            'net' => $this->change($income - $expense, $previousIncome - $previousExpense),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function change(int $current, int $previous): array
    {
        $delta = $current - $previous;

        return [
            'amount' => Money::fromCents($delta),
            'percent' => $previous > 0 ? round(($delta / $previous) * 100, 1) : null,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyActuals(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $monthExpression = DateExpressions::month('expense_date');
        $incomeExpression = DateExpressions::month('income_date');

        $expenses = DB::table('expenses')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('expense_date', '>=', $from->toDateString())->whereDate('expense_date', '<=', $to->toDateString())
            ->selectRaw("{$monthExpression} as month_key, SUM(amount) as total")
            ->groupByRaw($monthExpression)
            ->get()
            ->keyBy('month_key');

        $incomes = DB::table('incomes')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereDate('income_date', '>=', $from->toDateString())->whereDate('income_date', '<=', $to->toDateString())
            ->selectRaw("{$incomeExpression} as month_key, SUM(amount) as total")
            ->groupByRaw($incomeExpression)
            ->get()
            ->keyBy('month_key');

        $rows = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $incomeCents = Money::toCents((string) ($incomes[$key]->total ?? 0));
            $expenseCents = Money::toCents((string) ($expenses[$key]->total ?? 0));
            $monthStart = $month->startOfMonth();
            $monthEnd = $month->endOfMonth();

            $rows[] = [
                'month' => $key,
                'income_amount' => Money::fromCents($incomeCents),
                'expense_amount' => Money::fromCents($expenseCents),
                'net_amount' => Money::fromCents($incomeCents - $expenseCents),
                'is_partial' => $monthStart->lt($from) || $monthEnd->gt($to),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $commitmentData
     * @return array<string, mixed>
     */
    private function expectations(int $userId, CarbonImmutable $to, ?array $commitmentData): array
    {
        $today = FinancialCalendar::today();
        $windowEnd = $today->addDays(29);
        $rows = RecurringExpenseOccurrence::query()
            ->whereHas('schedule', fn ($schedule) => $schedule
                ->where('user_id', $userId)
                ->where('status', 'active'))
            ->where('status', 'planned')
            ->whereDate('occurrence_date', '>=', $today->toDateString())->whereDate('occurrence_date', '<=', $windowEnd->toDateString())
            ->get(['expected_amount']);
        $billCents = $rows->sum(fn (RecurringExpenseOccurrence $row): int => Money::toCents((string) $row->expected_amount));
        $subscriptionSummary = $commitmentData['subscription_breakdown']['summary']
            ?? $this->subscriptions->summaryForUser($userId, $today->toDateString());

        return [
            'as_of' => $today->toDateString(),
            'window_days' => 30,
            'upcoming_bill_count' => $rows->count(),
            'upcoming_bills_amount' => Money::fromCents((int) $billCents),
            'subscription_estimated_monthly_cost' => $subscriptionSummary['estimated_monthly_cost'] ?? '0.00',
            'subscription_estimated_annual_cost' => $subscriptionSummary['estimated_annual_cost'] ?? '0.00',
            'subscription_active_count' => $subscriptionSummary['active_count'] ?? 0,
            'note' => 'Expected bills and subscription estimates are obligations or estimates. They are not paid transactions and are never included in actual income or expense totals.',
            'as_of_period_end' => $to->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseReview(int $userId, string $from, string $to): array
    {
        $expenses = Expense::query()
            ->whereUser($userId)
            ->with('category:id,name')
            ->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->get(['id', 'amount', 'expense_date', 'description', 'category_id']);

        $averageCents = $expenses->isEmpty()
            ? 0
            : intdiv((int) $expenses->sum(fn (Expense $expense): int => Money::toCents((string) $expense->amount)), $expenses->count());
        $largest = $expenses
            ->sortByDesc(fn (Expense $expense): int => Money::toCents((string) $expense->amount))
            ->take(10)
            ->values();
        $anomalies = $expenses
            ->filter(fn (Expense $expense): bool => $expenses->count() >= 5
                && $averageCents > 0
                && Money::toCents((string) $expense->amount) > $averageCents * 3)
            ->sortByDesc(fn (Expense $expense): int => Money::toCents((string) $expense->amount))
            ->take(10)
            ->values();

        $present = fn (Expense $expense): array => [
            'id' => $expense->id,
            'date' => $expense->expense_date->format('Y-m-d'),
            'description' => $expense->description,
            'category_name' => $expense->category?->name,
            'amount' => (string) $expense->amount,
        ];

        return [
            'average_expense_amount' => Money::fromCents($averageCents),
            'large_expense_threshold' => $averageCents > 0 ? Money::fromCents($averageCents * 3) : null,
            'minimum_transactions_for_large_review' => 5,
            'largest_expenses' => $largest->map($present)->all(),
            'anomalies' => $anomalies->map($present)->all(),
            'note' => 'Large-expense review flags amounts above three times the selected-period average when at least five expenses exist. It is not a fraud finding.',
        ];
    }
}
