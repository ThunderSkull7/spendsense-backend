<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint assertions require PostgreSQL.');
    }
});

/**
 * Run an expected constraint violation inside a savepoint so the surrounding
 * RefreshDatabase transaction stays usable for the next assertion.
 */
function postgresExpectConstraint(callable $callback): void
{
    try {
        DB::transaction($callback);
    } catch (QueryException) {
        return;
    }

    throw new RuntimeException('Expected PostgreSQL to reject the write, but it succeeded.');
}

function stageThreeCategory(): Category
{
    return Category::create([
        'name' => 'Stage three '.fake()->unique()->numerify('####'),
        'type' => 'expense',
    ]);
}

function stageThreeGoal(User $user, array $overrides = []): SavingsGoal
{
    return SavingsGoal::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Constraint goal',
        'target_amount' => 100,
        'status' => 'active',
    ], $overrides));
}

test('PostgreSQL rejects invalid savings goal amounts and states', function () {
    $user = User::factory()->create();

    postgresExpectConstraint(fn () => stageThreeGoal($user, ['target_amount' => 0]));
    postgresExpectConstraint(fn () => stageThreeGoal($user, ['target_amount' => -1]));
    postgresExpectConstraint(fn () => stageThreeGoal($user, ['status' => 'paused']));

    expect(SavingsGoal::count())->toBe(0);
});

test('PostgreSQL enforces the append-only contribution ledger constraints', function () {
    $user = User::factory()->create();
    $goal = stageThreeGoal($user);

    $base = [
        'savings_goal_id' => $goal->id,
        'type' => 'contribution',
        'amount' => 10,
        'contributed_at' => '2026-10-20',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    postgresExpectConstraint(fn () => DB::table('savings_goal_contributions')
        ->insert(array_merge($base, ['amount' => 0])));
    postgresExpectConstraint(fn () => DB::table('savings_goal_contributions')
        ->insert(array_merge($base, ['amount' => -10])));
    postgresExpectConstraint(fn () => DB::table('savings_goal_contributions')
        ->insert(array_merge($base, ['type' => 'expense'])));

    DB::table('savings_goal_contributions')->insert($base);
    expect(DB::table('savings_goal_contributions')->count())->toBe(1);
});

test('PostgreSQL enforces budget alert ranges and identity', function () {
    $user = User::factory()->create();
    $category = stageThreeCategory();
    $budget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 100,
        'month_year' => '2026-10-01',
        'alert_thresholds' => [50, 80, 100],
    ]);

    $base = [
        'user_id' => $user->id,
        'budget_id' => $budget->id,
        'threshold' => 50,
        'period_month' => '2026-10-01',
        'spent_amount' => 50,
        'limit_amount' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('budget_alerts')->insert($base);

    postgresExpectConstraint(fn () => DB::table('budget_alerts')->insert($base));
    postgresExpectConstraint(fn () => DB::table('budget_alerts')->insert(array_merge($base, [
        'threshold' => 0,
        'period_month' => '2026-11-01',
    ])));
    postgresExpectConstraint(fn () => DB::table('budget_alerts')->insert(array_merge($base, [
        'threshold' => 101,
        'period_month' => '2026-11-01',
    ])));
    postgresExpectConstraint(fn () => DB::table('budget_alerts')->insert(array_merge($base, [
        'threshold' => 80,
        'period_month' => '2026-11-01',
        'spent_amount' => -1,
    ])));
    postgresExpectConstraint(fn () => DB::table('budget_alerts')->insert(array_merge($base, [
        'threshold' => 80,
        'period_month' => '2026-11-01',
        'limit_amount' => 0,
    ])));

    DB::table('budget_alerts')->insert(array_merge($base, [
        'threshold' => 80,
        'period_month' => '2026-11-01',
    ]));
    expect(DB::table('budget_alerts')->count())->toBe(2);
});
