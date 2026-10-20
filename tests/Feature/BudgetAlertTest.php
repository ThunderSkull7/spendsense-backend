<?php

use App\Models\Budget;
use App\Models\BudgetAlert;
use App\Models\Category;
use App\Models\Expense;
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

function makeAlertBudget(User $user, Category $category, float $limit = 100, array $thresholds = [50, 80, 100]): Budget
{
    return Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => $limit,
        'month_year' => '2026-10-01',
        'alert_thresholds' => $thresholds,
    ]);
}

function makeAlertExpense(User $user, Category $category, float $amount, string $date = '2026-10-10'): Expense
{
    return Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => $amount,
        'description' => 'Alert test expense',
        'expense_date' => $date,
    ]);
}

test('budget alerts require authentication', function () {
    $this->getJson('/api/budget-alerts')->assertStatus(401);
    $this->postJson('/api/budget-alerts/read-all')->assertStatus(401);
});

test('budget threshold defaults are exposed and configurable', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $created = $this->actingAs($user)
        ->postJson('/api/budgets', [
            'category_id' => $category->id,
            'limit_amount' => 100,
            'month_year' => '2026-10-01',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.alert_thresholds', [50, 80, 100])
        ->json('data');

    $this->actingAs($user)
        ->putJson("/api/budgets/{$created['id']}", [
            'alert_thresholds' => [25, 75, 90],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.alert_thresholds', [25, 75, 90]);

    $this->actingAs($user)
        ->putJson("/api/budgets/{$created['id']}", [
            'alert_thresholds' => [0, 101],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('alert_thresholds.0');

    $this->actingAs($user)
        ->putJson("/api/budgets/{$created['id']}", [
            'alert_thresholds' => [50, 50],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('alert_thresholds.1');
});

test('crossed thresholds are calculated from authoritative expenses', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeAlertBudget($user, $category, 100);
    makeAlertExpense($user, $category, 49.99);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.alerts');

    makeAlertExpense($user, $category, 0.01);

    $response = $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 50)
        ->assertJsonPath('data.alerts.0.spent_amount', '50.00')
        ->assertJsonPath('data.alerts.0.limit_amount', '100.00')
        ->assertJsonPath('data.alerts.0.percentage_used', 50)
        ->json('data');

    expect($response['alerts'][0]['message'])
        ->toContain($category->name)
        ->toContain('50.00')
        ->toContain('100.00')
        ->toContain('50%');

    makeAlertExpense($user, $category, 35);
    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 80)
        ->assertJsonPath('data.alerts.1.threshold', 50);

    makeAlertExpense($user, $category, 20);
    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(3, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 100)
        ->assertJsonPath('data.summary.overspent_count', 1);
});

test('custom thresholds and exact boundaries are respected', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeAlertBudget($user, $category, 200, [25, 75]);
    makeAlertExpense($user, $category, 50);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(1, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 25);

    makeAlertExpense($user, $category, 100);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 75);
});

test('repeated reads do not duplicate alerts and read state persists', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeAlertBudget($user, $category, 100);
    makeAlertExpense($user, $category, 80);

    $first = $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts')
        ->assertJsonPath('data.summary.unread', 2)
        ->json('data.alerts');

    $second = $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts')
        ->json('data.alerts');
    expect($second[0]['id'])->toBe($first[0]['id'])
        ->and($second[1]['id'])->toBe($first[1]['id']);

    $this->actingAs($user)
        ->postJson("/api/budget-alerts/{$first[0]['id']}/read")
        ->assertStatus(200)
        ->assertJsonPath('data.is_read', true);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonPath('data.summary.unread', 1)
        ->assertJsonPath('data.alerts.0.is_read', true);

    $this->actingAs($user)
        ->postJson('/api/budget-alerts/read-all', ['month' => '2026-10'])
        ->assertStatus(200)
        ->assertJsonPath('data.updated', 1);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonPath('data.summary.unread', 0);
});

test('soft deleted and edited expenses reconcile alert rows', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeAlertBudget($user, $category, 100);
    $expense = makeAlertExpense($user, $category, 80);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts');

    $this->actingAs($user)
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 45])
        ->assertStatus(200);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(0, 'data.alerts');

    $this->actingAs($user)
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 80])
        ->assertStatus(200);
    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts');

    $this->actingAs($user)
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(204);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(0, 'data.alerts');
});

test('changing a budget limit or category reconciles alerts', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $otherCategory = makeExpenseCategory();
    $budget = makeAlertBudget($user, $category, 100);
    makeAlertExpense($user, $category, 80);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts');

    $this->actingAs($user)
        ->putJson("/api/budgets/{$budget->id}", ['limit_amount' => 150])
        ->assertStatus(200);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(1, 'data.alerts')
        ->assertJsonPath('data.alerts.0.threshold', 50);

    $this->actingAs($user)
        ->putJson("/api/budgets/{$budget->id}", ['category_id' => $otherCategory->id])
        ->assertStatus(200);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(0, 'data.alerts');
});

test('budget alerts and reads are scoped to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();
    makeAlertBudget($owner, $category, 100);
    makeAlertExpense($owner, $category, 80);

    $alert = $this->actingAs($owner)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts')
        ->json('data.alerts.0');

    $this->actingAs($other)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(0, 'data.alerts');

    $this->actingAs($other)
        ->postJson("/api/budget-alerts/{$alert['id']}/read")
        ->assertStatus(403);

    $this->actingAs($other)
        ->postJson('/api/budget-alerts/read-all', ['month' => '2026-10'])
        ->assertStatus(200)
        ->assertJsonPath('data.updated', 0);

    expect(BudgetAlert::where('user_id', $owner->id)->whereNull('read_at')->count())->toBe(2);
});

test('alerts are month scoped and stale rows are removed when budgets disappear', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = makeAlertBudget($user, $category, 100);
    makeAlertExpense($user, $category, 80);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-09')
        ->assertJsonCount(0, 'data.alerts');

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(2, 'data.alerts');

    $this->actingAs($user)
        ->deleteJson("/api/budgets/{$budget->id}")
        ->assertStatus(204);

    $this->actingAs($user)
        ->getJson('/api/budget-alerts?month=2026-10')
        ->assertJsonCount(0, 'data.alerts');

    $this->assertDatabaseCount('budget_alerts', 0);
});
