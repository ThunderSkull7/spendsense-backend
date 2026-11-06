<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('financial health endpoint requires authentication', function () {
    $this->getJson('/api/analytics/financial-health')->assertStatus(401);
});

test('financial health computes accurate cash flow, savings rate, and spending change', function () {
    $user = User::factory()->create();
    $cat = Category::create([
        'user_id' => $user->id,
        'name'    => 'General',
        'type'    => 'expense',
    ]);

    // Prior month: 2026-09
    Expense::create([
        'user_id'      => $user->id,
        'category_id'  => $cat->id,
        'amount'       => 2000.00,
        'expense_date' => '2026-09-15',
    ]);

    // Current month: 2026-10
    Income::create([
        'user_id'     => $user->id,
        'source'      => 'Salary',
        'amount'      => 10000.00,
        'income_date' => '2026-10-01',
    ]);

    Expense::create([
        'user_id'      => $user->id,
        'category_id'  => $cat->id,
        'amount'       => 4000.00,
        'expense_date' => '2026-10-10',
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/financial-health?month=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.period.month_key', '2026-10')
        ->assertJsonPath('data.indicators.net_cash_flow.income', '10000.00')
        ->assertJsonPath('data.indicators.net_cash_flow.expenses', '4000.00')
        ->assertJsonPath('data.indicators.net_cash_flow.net', '6000.00')
        ->assertJsonPath('data.indicators.net_cash_flow.status', 'positive')
        // Savings rate: (10000 - 4000) / 10000 * 100 = 60.0%
        ->assertJsonPath('data.indicators.savings_rate.rate_percent', fn ($v) => (float) $v === 60.0)
        ->assertJsonPath('data.indicators.savings_rate.status', 'positive')
        // Spending change: (4000 - 2000) / 2000 * 100 = +100.0%
        ->assertJsonPath('data.indicators.spending_change.change_percent', fn ($v) => (float) $v === 100.0)
        ->assertJsonPath('data.indicators.spending_change.direction', 'higher');

    expect($response->json('data.definitions.net_cash_flow'))->toBeString();
    expect($response->json('data.definitions.savings_rate'))->toBeString();
});

test('financial health handles zero income and zero comparison gracefully without divide by zero', function () {
    $user = User::factory()->create();
    $cat = Category::create([
        'user_id' => $user->id,
        'name'    => 'Misc',
        'type'    => 'expense',
    ]);

    // Current month: 2026-10 with expenses only, no income, no prior spending
    Expense::create([
        'user_id'      => $user->id,
        'category_id'  => $cat->id,
        'amount'       => 1500.00,
        'expense_date' => '2026-10-05',
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/financial-health?month=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.indicators.net_cash_flow.income', '0.00')
        ->assertJsonPath('data.indicators.net_cash_flow.expenses', '1500.00')
        ->assertJsonPath('data.indicators.net_cash_flow.net', '-1500.00')
        ->assertJsonPath('data.indicators.net_cash_flow.status', 'negative')
        // Savings rate should be null and status not_available
        ->assertJsonPath('data.indicators.savings_rate.rate_percent', null)
        ->assertJsonPath('data.indicators.savings_rate.status', 'not_available')
        ->assertJsonPath('data.indicators.savings_rate.label', 'Not available')
        // No prior spending in 2026-09
        ->assertJsonPath('data.indicators.spending_change.change_percent', null)
        ->assertJsonPath('data.indicators.spending_change.direction', 'new_spending');
});

test('financial health integrates budget utilization and observations', function () {
    $user = User::factory()->create();
    $cat = Category::create([
        'user_id' => $user->id,
        'name'    => 'Dining Out',
        'type'    => 'expense',
    ]);

    // Budget: 5000 for Dining Out in 2026-10
    Budget::create([
        'user_id'      => $user->id,
        'category_id'  => $cat->id,
        'limit_amount' => 5000.00,
        'month_year'   => '2026-10-01',
    ]);

    // Expense: 6000 (exceeds budget by 20%)
    Expense::create([
        'user_id'      => $user->id,
        'category_id'  => $cat->id,
        'amount'       => 6000.00,
        'expense_date' => '2026-10-15',
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/financial-health?month=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.indicators.budget_utilization.total_limit', '5000.00')
        ->assertJsonPath('data.indicators.budget_utilization.total_spent', '6000.00')
        ->assertJsonPath('data.indicators.budget_utilization.utilization_percent', fn ($v) => (float) $v === 120.0)
        ->assertJsonPath('data.indicators.budget_utilization.over_budget_count', 1)
        ->assertJsonPath('data.indicators.budget_utilization.status', 'exceeded');

    // Observations should flag budget exceeded
    $obs = collect($response->json('data.observations'));
    expect($obs->contains(fn ($o) => str_contains($o['title'], 'budget(s) exceeded')))->toBeTrue();
});

test('financial health is owner scoped', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $cat2 = Category::create([
        'user_id' => $user2->id,
        'name'    => 'Bills',
        'type'    => 'expense',
    ]);

    Income::create(['user_id' => $user1->id, 'source' => 'Job', 'amount' => 50000.00, 'income_date' => '2026-10-01']);
    Expense::create(['user_id' => $user2->id, 'category_id' => $cat2->id, 'amount' => 30000.00, 'expense_date' => '2026-10-01']);

    $resp1 = $this->actingAs($user1)->getJson('/api/analytics/financial-health?month=2026-10');
    $resp1->assertOk()
        ->assertJsonPath('data.indicators.net_cash_flow.income', '50000.00')
        ->assertJsonPath('data.indicators.net_cash_flow.expenses', '0.00');

    $resp2 = $this->actingAs($user2)->getJson('/api/analytics/financial-health?month=2026-10');
    $resp2->assertOk()
        ->assertJsonPath('data.indicators.net_cash_flow.income', '0.00')
        ->assertJsonPath('data.indicators.net_cash_flow.expenses', '30000.00');
});
