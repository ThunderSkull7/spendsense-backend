<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\RecurringExpenseOccurrence;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function forecastCategory(string $name = 'Forecast category'): Category
{
    return Category::create(['name' => $name, 'type' => 'expense']);
}

function forecastIncomeSchedule($test, User $user, array $overrides = []): array
{
    return $test->actingAs($user)->postJson('/api/recurring-incomes', array_merge([
        'name' => 'Salary',
        'amount' => '2000.00',
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'end_date' => '2027-10-01',
    ], $overrides))->assertCreated()->json('data');
}

function forecastExpenseSchedule($test, User $user, Category $category, array $overrides = []): array
{
    return $test->actingAs($user)->postJson('/api/recurring-expenses', array_merge([
        'description' => 'Monthly bill',
        'amount' => '100.00',
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-25',
    ], $overrides))->assertCreated()->json('data');
}

test('cash flow forecast projects planned occurrences and keeps actuals as context only', function () {
    $user = User::factory()->create();
    $category = forecastCategory();

    forecastIncomeSchedule($this, $user);
    forecastExpenseSchedule($this, $user, $category);

    Income::create([
        'user_id' => $user->id,
        'amount' => '900.00',
        'source' => 'Historical income',
        'income_date' => '2026-09-01',
        'frequency' => 'one-time',
    ]);
    Expense::create([
        'user_id' => $user->id,
        'amount' => '300.00',
        'category_id' => $category->id,
        'description' => 'Historical expense',
        'expense_date' => '2026-09-02',
    ]);

    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Separate tracker',
        'amount' => '20.00',
        'frequency' => 'monthly',
        'next_renewal_date' => '2026-10-28',
        'category_id' => $category->id,
    ])->assertCreated();

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=30&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.as_of', '2026-10-20')
        ->assertJsonPath('data.horizon_days', 30)
        ->assertJsonPath('data.window.start', '2026-10-20')
        ->assertJsonPath('data.window.end', '2026-11-18')
        ->assertJsonPath('data.window.timezone', 'UTC')
        ->assertJsonPath('data.expected_income', '2000.00')
        ->assertJsonPath('data.expected_expenses', '100.00')
        ->assertJsonPath('data.projected_net_cash_flow', '1900.00')
        ->assertJsonPath('data.starting_balance', null)
        ->assertJsonPath('data.starting_balance_status', 'unavailable')
        ->assertJsonPath('data.ending_balance', null)
        ->assertJsonPath('data.negative_cash_flow_status', 'not_calculable')
        ->assertJsonPath('data.negative_cash_flow_dates', [])
        ->assertJsonPath('data.expected_income_count', 1)
        ->assertJsonPath('data.expected_expense_count', 1)
        ->assertJsonPath('data.historical_actuals.average_monthly_income', '300.00')
        ->assertJsonPath('data.historical_actuals.average_monthly_expenses', '100.00')
        ->assertJsonPath('data.historical_actuals.months', 3)
        ->assertJsonPath('data.subscriptions.active_count', 1)
        ->assertJsonPath('data.subscriptions.estimated_monthly_cost', '20.00')
        ->assertJsonPath('data.monthly_projection.0.month', '2026-10')
        ->assertJsonPath('data.monthly_projection.0.expected_expenses', '100.00')
        ->assertJsonPath('data.monthly_projection.1.month', '2026-11')
        ->assertJsonPath('data.monthly_projection.1.expected_income', '2000.00');

    // The separate subscription summary is not added to expected expenses.
    expect($this->actingAs($user)->getJson('/api/analytics/cash-flow-forecast?horizon=30')->json('data.expected_expenses'))
        ->toBe('100.00');
    expect(Income::count())->toBe(1);
    expect(Expense::count())->toBe(1);
});

test('forecast excludes paused and stopped schedules and paid or cancelled occurrences', function () {
    $user = User::factory()->create();
    $category = forecastCategory();

    $income = forecastIncomeSchedule($this, $user);
    $this->actingAs($user)->postJson("/api/recurring-incomes/{$income['id']}/pause")->assertOk();

    $expense = forecastExpenseSchedule($this, $user, $category);
    $schedule = RecurringExpense::findOrFail($expense['id']);
    $occurrence = RecurringExpenseOccurrence::where('recurring_expense_id', $schedule->id)
        ->whereDate('occurrence_date', '2026-10-25')
        ->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay",
        ['actual_amount' => '100.00', 'paid_date' => '2026-10-25']
    )->assertOk();

    // A stopped schedule is excluded even if a planned occurrence somehow
    // remains in the materialized table.
    $otherCategory = forecastCategory('Stopped category');
    $stopped = forecastExpenseSchedule($this, $user, $otherCategory, [
        'description' => 'Stopped bill',
        'start_date' => '2026-10-22',
    ]);
    RecurringExpense::whereKey($stopped['id'])->update(['status' => 'stopped']);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=30&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.expected_income', '0.00')
        ->assertJsonPath('data.expected_expenses', '0.00')
        ->assertJsonPath('data.expected_income_count', 0)
        ->assertJsonPath('data.expected_expense_count', 0)
        ->assertJsonPath('data.schedule_context.recurring_income.paused', 1)
        ->assertJsonPath('data.schedule_context.recurring_expense.stopped', 1);

    expect($response->json('data.events'))->toBe([]);
    expect(Expense::count())->toBe(1);
});

test('forecast reports overdue commitments separately without re-dating them', function () {
    $user = User::factory()->create();
    $category = forecastCategory();

    forecastExpenseSchedule($this, $user, $category, [
        'description' => 'Overdue bill',
        'amount' => '50.00',
        'start_date' => '2026-10-25',
    ]);
    $schedule = RecurringExpense::query()->latest('id')->firstOrFail();
    RecurringExpenseOccurrence::query()
        ->where('recurring_expense_id', $schedule->id)
        ->whereDate('occurrence_date', '2026-10-25')
        ->update(['occurrence_date' => '2026-10-15']);

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=30&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.expected_expenses', '0.00')
        ->assertJsonPath('data.overdue_commitments.count', 1)
        ->assertJsonPath('data.overdue_commitments.total', '50.00')
        ->assertJsonPath('data.overdue_commitments.included_in_forecast', false);
});

test('forecast horizons are bounded and can be selected', function () {
    $user = User::factory()->create();
    $category = forecastCategory();
    forecastIncomeSchedule($this, $user);
    forecastExpenseSchedule($this, $user, $category);

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=60&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.window.end', '2026-12-18')
        ->assertJsonPath('data.expected_income', '4000.00')
        ->assertJsonPath('data.expected_expenses', '200.00');

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=90&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.window.end', '2027-01-17');

    $this->actingAs($user)->getJson('/api/analytics/cash-flow-forecast?horizon=45')
        ->assertStatus(422);
    $this->actingAs($user)->getJson('/api/analytics/cash-flow-forecast?as_of=2026-02-30')
        ->assertStatus(422);
});

test('forecast is owner scoped read only and requires authentication', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = forecastCategory();

    forecastIncomeSchedule($this, $other);
    forecastExpenseSchedule($this, $other, $category);

    $tables = ['expenses', 'incomes', 'recurring_expenses', 'recurring_incomes', 'recurring_expense_occurrences', 'recurring_income_occurrences'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->count();
    }
    $expenseStatuses = RecurringExpenseOccurrence::query()->orderBy('id')->pluck('status', 'id')->all();
    $incomeStatuses = RecurringIncomeOccurrence::query()->orderBy('id')->pluck('status', 'id')->all();

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow-forecast?horizon=30&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.expected_income', '0.00')
        ->assertJsonPath('data.expected_expenses', '0.00');

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table]);
    }
    expect(RecurringExpenseOccurrence::query()->orderBy('id')->pluck('status', 'id')->all())
        ->toBe($expenseStatuses);
    expect(RecurringIncomeOccurrence::query()->orderBy('id')->pluck('status', 'id')->all())
        ->toBe($incomeStatuses);

    auth()->forgetGuards();
    $this->getJson('/api/analytics/cash-flow-forecast')->assertStatus(401);
});

test('cash flow forecast requires authentication', function () {
    $this->getJson('/api/analytics/cash-flow-forecast')->assertStatus(401);
});
