<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\RecurringExpenseOccurrence;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function insightsCategory(string $name = 'Food', string $type = 'expense'): Category
{
    return Category::create(['name' => $name, 'type' => $type]);
}

function insightsExpense(User $user, Category $category, string $amount, string $date): Expense
{
    return Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => $amount,
        'description' => 'Stage 4 expense',
        'expense_date' => $date,
    ]);
}

function insightsIncome(User $user, string $amount, string $date): Income
{
    return Income::create([
        'user_id' => $user->id,
        'amount' => $amount,
        'source' => 'Stage 4 income',
        'income_date' => $date,
        'frequency' => 'one-time',
    ]);
}

test('spending insights calculates month totals comparisons and category shares', function () {
    $user = User::factory()->create();
    $food = insightsCategory('Food');
    $rent = insightsCategory('Rent');

    insightsIncome($user, '2500.00', '2026-09-01');
    insightsExpense($user, $food, '200.00', '2026-09-05');

    insightsIncome($user, '3000.00', '2026-10-01');
    insightsExpense($user, $food, '300.00', '2026-10-02');
    insightsExpense($user, $food, '100.00', '2026-10-03');
    insightsExpense($user, $rent, '500.00', '2026-10-04');

    $response = $this->actingAs($user)->getJson(
        '/api/analytics/spending-insights?from=2026-09&to=2026-10'
    );

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.period.month_count', 2)
        ->assertJsonPath('data.period.timezone', 'UTC')
        ->assertJsonPath('data.summary.total_income', '5500.00')
        ->assertJsonPath('data.summary.total_expenses', '1100.00')
        ->assertJsonPath('data.summary.net_cash_flow', '4400.00')
        ->assertJsonPath('data.summary.average_monthly_expenses', '550.00')
        ->assertJsonPath('data.summary.average_monthly_income', '2750.00')
        ->assertJsonPath('data.summary.months_with_data', 2)
        ->assertJsonPath('data.summary.expense_count', 4)
        ->assertJsonPath('data.summary.largest_category.category_name', 'Food')
        ->assertJsonPath('data.comparison.current_month', '2026-10')
        ->assertJsonPath('data.comparison.previous_month', '2026-09')
        ->assertJsonPath('data.comparison.current.expenses', '900.00')
        ->assertJsonPath('data.comparison.previous.expenses', '200.00')
        ->assertJsonPath('data.comparison.expense_change_percent', 350)
        ->assertJsonPath('data.comparison.income_change_percent', 20)
        ->assertJsonPath('data.categories.0.category_name', 'Food')
        ->assertJsonPath('data.categories.0.total_spent', '600.00')
        ->assertJsonPath('data.categories.0.transaction_count', 3)
        ->assertJsonPath('data.categories.0.average_transaction', '200.00')
        ->assertJsonPath('data.categories.0.share_percent', 54.55)
        ->assertJsonPath('data.categories.1.category_name', 'Rent')
        ->assertJsonPath('data.months.0.month', '2026-09')
        ->assertJsonPath('data.months.0.net', '2300.00')
        ->assertJsonPath('data.months.1.month', '2026-10')
        ->assertJsonPath('data.months.1.net', '2100.00')
        ->assertJsonPath('data.largest_transactions.0.amount', '500.00')
        ->assertJsonPath('data.largest_transactions.0.category_name', 'Rent');
});

test('spending insights reflects edits soft deletes restores and utc month boundaries', function () {
    $user = User::factory()->create();
    $category = insightsCategory();

    $older = insightsExpense($user, $category, '50.00', '2026-09-30');
    $active = insightsExpense($user, $category, '80.00', '2026-10-01');
    $deleted = insightsExpense($user, $category, '120.00', '2026-10-02');
    $deletedIncome = insightsIncome($user, '500.00', '2026-10-03');

    $this->actingAs($user)->deleteJson("/api/expenses/{$deleted->id}")->assertNoContent();
    $this->actingAs($user)->deleteJson("/api/incomes/{$deletedIncome->id}")->assertNoContent();

    $response = $this->actingAs($user)
        ->putJson("/api/expenses/{$active->id}", [
            'amount' => '100.00',
            'category_id' => $category->id,
            'expense_date' => '2026-10-01',
            'description' => 'Edited',
        ]);
    $response->assertOk();

    $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '100.00')
        ->assertJsonPath('data.summary.total_income', '0.00')
        ->assertJsonPath('data.summary.expense_count', 1)
        ->assertJsonPath('data.categories.0.average_transaction', '100.00');

    $september = $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-09&to=2026-09');
    $september
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '50.00');

    $this->actingAs($user)->postJson("/api/expenses/{$deleted->id}/restore")->assertOk();
    $this->actingAs($user)->postJson("/api/incomes/{$deletedIncome->id}/restore")->assertOk();

    $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '220.00')
        ->assertJsonPath('data.summary.total_income', '500.00')
        ->assertJsonPath('data.summary.expense_count', 2);

    // A UTC calendar month excludes the previous month's final date.
    expect($older->expense_date->toDateString())->toBe('2026-09-30');
});

test('spending insights applies the documented anomaly rule and handles insufficient history', function () {
    $user = User::factory()->create();
    $category = insightsCategory();

    foreach (['10.00', '10.00', '10.00', '10.00', '10.00', '100.00'] as $index => $amount) {
        insightsExpense($user, $category, $amount, '2026-10-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT));
    }

    $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.anomalies.eligible', true)
        ->assertJsonPath('data.anomalies.expense_count', 6)
        ->assertJsonPath('data.anomalies.average_expense', '25.00')
        ->assertJsonPath('data.anomalies.threshold_amount', '75.00')
        ->assertJsonPath('data.anomalies.transactions.0.amount', '100.00')
        ->assertJsonCount(1, 'data.anomalies.transactions');

    $sparseUser = User::factory()->create();
    insightsExpense($sparseUser, $category, '10.00', '2026-10-01');
    insightsExpense($sparseUser, $category, '20.00', '2026-10-02');

    $this->actingAs($sparseUser)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.anomalies.eligible', false)
        ->assertJsonPath('data.anomalies.transactions', [])
        ->assertJsonPath('data.anomalies.minimum_expenses', 5);
});

test('spending insights budget recurring and subscription summaries do not double count actuals', function () {
    $user = User::factory()->create();
    $category = insightsCategory();

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-10-01',
        'alert_thresholds' => [50, 80, 100],
    ]);
    insightsExpense($user, $category, '80.00', '2026-10-10');

    $scheduleResponse = $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Internet',
        'amount' => '50.00',
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-05',
        'reminder_days_before' => 3,
    ])->assertCreated();
    $scheduleId = $scheduleResponse->json('data.id');

    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Streaming',
        'provider' => 'Example',
        'amount' => '12.99',
        'frequency' => 'monthly',
        'next_renewal_date' => '2026-11-02',
        'category_id' => $category->id,
        'status' => 'active',
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Home internet',
        'provider' => 'ISP',
        'recurring_expense_id' => $scheduleId,
        'status' => 'active',
    ])->assertCreated();

    $response = $this->actingAs($user)
        ->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '80.00')
        ->assertJsonPath('data.recurring_expenses.active_count', 1)
        ->assertJsonPath('data.recurring_expenses.estimated_monthly_cost', '50.00')
        ->assertJsonPath('data.recurring_expenses.upcoming_30_days_amount', '50.00')
        ->assertJsonPath('data.subscriptions.active_count', 2)
        ->assertJsonPath('data.subscriptions.linked_count', 1)
        ->assertJsonPath('data.subscriptions.estimated_monthly_cost', '62.99')
        ->assertJsonPath('data.subscriptions.estimated_annual_cost', '755.88')
        ->assertJsonPath('data.budget_utilization.latest_month', '2026-10')
        ->assertJsonPath('data.budget_utilization.summary.total_limit', '100.00')
        ->assertJsonPath('data.budget_utilization.summary.total_spent', '80.00')
        ->assertJsonPath('data.budget_utilization.summary.utilization_percent', 80)
        ->assertJsonPath('data.budget_utilization.summary.overspent_count', 0)
        ->assertJsonPath('data.budget_utilization.items.0.category_name', 'Food');

    expect($response->json('data.notes'))->toBeArray();
    expect(Subscription::count())->toBe(2);
    expect(RecurringExpense::count())->toBe(1);
});

test('paying a recurring bill contributes exactly one actual expense to insights', function () {
    $user = User::factory()->create();
    $category = insightsCategory();

    $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Monthly bill',
        'amount' => '50.00',
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-05',
    ])->assertCreated();

    $occurrence = RecurringExpenseOccurrence::query()->firstOrFail();
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$occurrence->recurring_expense_id}/occurrences/{$occurrence->id}/pay",
        ['actual_amount' => '50.00', 'paid_date' => '2026-10-05']
    )->assertOk();

    $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '50.00')
        ->assertJsonPath('data.summary.expense_count', 1)
        ->assertJsonPath('data.recurring_expenses.estimated_monthly_cost', '50.00');

    expect(Expense::count())->toBe(1);
});

test('spending insights is owner scoped and validates periods without writing data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = insightsCategory();

    insightsExpense($other, $category, '999.00', '2026-10-05');
    insightsIncome($other, '999.00', '2026-10-01');

    $this->actingAs($user)->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-10')
        ->assertOk()
        ->assertJsonPath('data.summary.total_expenses', '0.00')
        ->assertJsonPath('data.summary.total_income', '0.00')
        ->assertJsonPath('data.summary.has_data', false);

    $before = [Expense::count(), Income::count()];

    $this->actingAs($user)
        ->getJson('/api/analytics/spending-insights?from=2026-10&to=2026-09')
        ->assertStatus(422);
    $this->actingAs($user)
        ->getJson('/api/analytics/spending-insights?from=2024-01&to=2026-10')
        ->assertStatus(422);
    $this->actingAs($user)
        ->getJson('/api/analytics/spending-insights?from=2026-13&to=2026-10')
        ->assertStatus(422);

    auth()->forgetGuards();
    $this->getJson('/api/analytics/spending-insights')->assertStatus(401);
    expect([Expense::count(), Income::count()])->toBe($before);
});

test('spending insights requires authentication', function () {
    $this->getJson('/api/analytics/spending-insights')->assertStatus(401);
});
