<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----------- Monthly Cash Flow Tests -----------

test('authenticated user can get monthly cash flow', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    Income::factory()->create([
        'user_id'    => $user->id,
        'amount'     => 1000,
        'income_date' => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 300,
        'expense_date' => $now->toDateString(),
        'category_id'  => makeExpenseCategory()->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total_income', 1000)
        ->assertJsonPath('data.total_expenses', 300)
        ->assertJsonPath('data.net_cash_flow', 700);
});

test('unauthenticated user cannot access cash flow', function () {
    $this->getJson('/api/analytics/cash-flow')->assertStatus(401);
});

test('cash flow defaults to current month', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    Income::factory()->create([
        'user_id'     => $user->id,
        'amount'      => 500,
        'income_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/cash-flow');

    $response->assertStatus(200)
        ->assertJsonPath('data.month', $now->month)
        ->assertJsonPath('data.year', $now->year);
});

test('cash flow handles empty data', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.total_income', 0)
        ->assertJsonPath('data.total_expenses', 0)
        ->assertJsonPath('data.net_cash_flow', 0);
});

test('cash flow returns 422 for invalid month', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?month=13')
        ->assertStatus(422);

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?month=0')
        ->assertStatus(422);
});

test('cash flow is scoped to authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $now = Carbon::now();

    Income::factory()->create([
        'user_id'     => $userA->id,
        'amount'      => 1000,
        'income_date' => $now->toDateString(),
    ]);

    Income::factory()->create([
        'user_id'     => $userB->id,
        'amount'      => 2000,
        'income_date' => $now->toDateString(),
    ]);

    $responseA = $this->actingAs($userA)
        ->getJson('/api/analytics/cash-flow?year=' . $now->year . '&month=' . $now->month);

    $responseA->assertJsonPath('data.total_income', 1000);
});

// ----------- Spending by Category Tests -----------

test('authenticated user can get spending breakdown', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 100,
        'category_id'  => $category->id,
        'expense_date' => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 150,
        'category_id'  => $category->id,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/spending-by-category?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.breakdown.0.total_spent', 250)
        ->assertJsonPath('data.breakdown.0.count', 2);
});

test('spending breakdown groups by category', function () {
    $user = User::factory()->create();
    $cat1 = makeExpenseCategory();
    $cat2 = makeExpenseCategory();
    $now = Carbon::now();

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 100,
        'category_id'  => $cat1->id,
        'expense_date' => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 200,
        'category_id'  => $cat2->id,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/spending-by-category?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200);
    $this->assertCount(2, $response->json('data.breakdown'));
});

test('spending breakdown handles empty data', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/spending-by-category?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.breakdown', []);
});

test('spending breakdown is user-scoped', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Expense::factory()->create([
        'user_id'      => $userA->id,
        'amount'       => 100,
        'category_id'  => $category->id,
        'expense_date' => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $userB->id,
        'amount'       => 500,
        'category_id'  => $category->id,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($userA)
        ->getJson('/api/analytics/spending-by-category?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.breakdown.0.total_spent', 100);
});

// ----------- Month Comparison Tests -----------

test('authenticated user can compare months', function () {
    $user = User::factory()->create();
    $now = Carbon::now();
    $prev = $now->clone()->subMonth();

    // Current month
    Income::factory()->create([
        'user_id'     => $user->id,
        'amount'      => 1500,
        'income_date' => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'amount'       => 400,
        'expense_date' => $now->toDateString(),
        'category_id'  => makeExpenseCategory()->id,
    ]);

    // Previous month
    Income::factory()->create([
        'user_id'     => $user->id,
        'amount'      => 1000,
        'income_date' => $prev->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/month-comparison?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.current_month.income', 1500)
        ->assertJsonPath('data.previous_month.income', 1000);
});

test('month comparison calculates percentage change', function () {
    $user = User::factory()->create();
    $now = Carbon::now();
    $prev = $now->clone()->subMonth();

    // Current: 2000, Previous: 1000 = 100% increase
    Income::factory()->create([
        'user_id'     => $user->id,
        'amount'      => 2000,
        'income_date' => $now->toDateString(),
    ]);

    Income::factory()->create([
        'user_id'     => $user->id,
        'amount'      => 1000,
        'income_date' => $prev->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/month-comparison?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.change_percentage.income', 100);
});

test('month comparison handles different months correctly', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/month-comparison?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.current_month.month', (string)$now->month)
        ->assertJsonPath('data.current_month.year', (string)$now->year);
});

// ----------- Budget Status Tests -----------

test('authenticated user can get budget status', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    $budget = Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 500,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 300,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-status?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.budgets.0.limit_amount', 500)
        ->assertJsonPath('data.budgets.0.spent_amount', 300)
        ->assertJsonPath('data.budgets.0.remaining_amount', 200);
});

test('budget status calculates percentage used', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 1000,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 500,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-status?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.budgets.0.percentage_used', 50);
});

test('budget status flags overspending', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 500,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 600,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-status?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.budgets.0.is_overspent', true)
        ->assertJsonPath('data.budgets.0.percentage_used', 120);
});

test('budget status handles no budgets', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-status?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.budgets', []);
});

test('budget status is user-scoped', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $userA->id,
        'category_id'  => $category->id,
        'limit_amount' => 500,
        'month_year'   => $now->toDateString(),
    ]);

    Budget::factory()->create([
        'user_id'      => $userB->id,
        'category_id'  => $category->id,
        'limit_amount' => 1000,
        'month_year'   => $now->toDateString(),
    ]);

    $response = $this->actingAs($userA)
        ->getJson('/api/analytics/budget-status?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.budgets.0.limit_amount', 500);
    $this->assertCount(1, $response->json('data.budgets'));
});

// ----------- Budget Alerts Tests -----------

test('authenticated user can get budget alerts', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 500,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 450,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.alert_count', 1);
});

test('budget alerts flag near-limit status (>80%)', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 1000,
        'month_year'   => $now->toDateString(),
    ]);

    // 85% used
    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 850,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.alerts.0.alert_type', 'near_limit');
});

test('budget alerts flag overspending', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 500,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 600,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.alerts.0.alert_type', 'overspent');
});

test('budget alerts ignores budgets under 80%', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $now = Carbon::now();

    Budget::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => 1000,
        'month_year'   => $now->toDateString(),
    ]);

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 500,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?year=' . $now->year . '&month=' . $now->month);

    $response->assertJsonPath('data.alert_count', 0)
        ->assertJsonPath('data.alerts', []);
});

test('budget alerts handles no alerts', function () {
    $user = User::factory()->create();
    $now = Carbon::now();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?year=' . $now->year . '&month=' . $now->month);

    $response->assertStatus(200)
        ->assertJsonPath('data.alert_count', 0);
});

test('budget alerts returns 422 for invalid month', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/analytics/budget-alerts?month=13')
        ->assertStatus(422);
});

test('unauthenticated user cannot access budget alerts', function () {
    $this->getJson('/api/analytics/budget-alerts')->assertStatus(401);
});
