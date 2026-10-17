<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Consolidated remediation — regression tests
|--------------------------------------------------------------------------
| Covers the defects fixed in this batch:
|  - D8/D17  Production hardening guard (debug off, secure cookies in prod)
|  - D13     Database-level ON DELETE RESTRICT protection for categories
|  - D14     Category type enum + incomes carry no category linkage
|  - D15     Budget alert thresholds (80% boundary, overspent flag)
|  - D18     Expense deletion while its category has other dependents
*/

// ----------- D8 / D17: production hardening guard -----------

test('production environment forces debug off and secure session cookies', function () {
    $this->app['env'] = 'production';
    config(['app.debug' => true, 'session.secure' => false]);

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeFalse()
        ->and(config('session.secure'))->toBeTrue();
});

test('local environment keeps its configured debug and cookie settings', function () {
    $this->app['env'] = 'local';
    config(['app.debug' => true, 'session.secure' => null]);

    (new AppServiceProvider($this->app))->boot();

    expect(config('app.debug'))->toBeTrue()
        ->and(config('session.secure'))->toBeNull();
});

// ----------- helpers -----------

function makeBudgetForMonth(User $user, Category $category, float $limit, Carbon $month): Budget
{
    return Budget::create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'limit_amount' => $limit,
        'month_year'   => $month->copy()->toDateString(),
    ]);
}

// ----------- D15: budget alert thresholds -----------

test('budget alerts trigger at exactly 80 percent used', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $now      = Carbon::now();

    makeBudgetForMonth($user, $category, 1000, $now);
    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 800,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson(
        "/api/analytics/budget-alerts?year={$now->year}&month={$now->month}"
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.alert_count', 1)
        ->assertJsonPath('data.alerts.0.alert_type', 'near_limit');
});

test('budget alerts ignore budgets just below 80 percent used', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $now      = Carbon::now();

    makeBudgetForMonth($user, $category, 1000, $now);
    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 799.99,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson(
        "/api/analytics/budget-alerts?year={$now->year}&month={$now->month}"
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.alert_count', 0)
        ->assertJsonPath('data.alerts', []);
});

test('budget alerts are flagged overspent when spending exceeds the limit', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $now      = Carbon::now();

    makeBudgetForMonth($user, $category, 500, $now);
    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 650,
        'expense_date' => $now->toDateString(),
    ]);

    $response = $this->actingAs($user)->getJson(
        "/api/analytics/budget-alerts?year={$now->year}&month={$now->month}"
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.alert_count', 1)
        ->assertJsonPath('data.alerts.0.alert_type', 'overspent')
        ->assertJsonPath('data.alerts.0.message', 'Budget exceeded by 150');
});

// ----------- D18: expense deletion with category dependents -----------

test('an expense can be deleted while its category has other dependents', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $now      = Carbon::now();

    $budget = makeBudgetForMonth($user, $category, 500, $now);

    $keep = Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 100,
        'expense_date' => $now->toDateString(),
    ]);
    $delete = Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 200,
        'expense_date' => $now->toDateString(),
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/expenses/{$delete->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('expenses', ['id' => $delete->id]);
    $this->assertDatabaseHas('expenses', ['id' => $keep->id]);
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
    $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
});

// ----------- D13: database-level FK restrict protection -----------

test('category deletion is blocked at the database level while an expense references it', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();

    Expense::factory()->create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 25,
        'expense_date' => Carbon::now()->toDateString(),
    ]);

    expect(fn () => DB::table('categories')->where('id', $category->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('category deletion is blocked at the database level while a budget references it', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $budget   = makeBudgetForMonth($user, $category, 100, Carbon::now());

    expect(fn () => DB::table('categories')->where('id', $category->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
    $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
});

test('an unreferenced category can be deleted at the database level', function () {
    $category = makeExpenseCategory();

    $deleted = DB::table('categories')->where('id', $category->id)->delete();

    expect($deleted)->toBe(1);
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

// ----------- D14: category enum and income contract -----------

test('category types are limited to the exact income/expense enum', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Enum Check Savings', 'type' => 'Savings'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Enum Check Capitalised', 'type' => 'Expense'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Enum Check Valid', 'type' => 'expense'])
        ->assertStatus(201);
});

test('income creation ignores payload fields outside the API contract', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/incomes', [
        'amount'      => 120,
        'source'      => 'Regression Salary',
        'income_date' => '2025-01-15',
        'category_id' => 1,
        'frequency'   => 'Monthly',
        'notes'       => 'ignored by contract',
    ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    $this->assertDatabaseHas('incomes', [
        'user_id' => $user->id,
        'source'  => 'Regression Salary',
        'amount'  => 120,
    ]);
});
