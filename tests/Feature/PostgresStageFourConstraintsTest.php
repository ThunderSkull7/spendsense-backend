<?php

use App\Models\Category;
use App\Models\NetWorthEntry;
use App\Models\NetWorthValuation;
use App\Models\RecurringExpense;
use App\Models\Subscription;
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

function stageFourExpectConstraint(callable $callback): void
{
    try {
        DB::transaction($callback);
    } catch (QueryException) {
        return;
    }

    throw new RuntimeException('Expected PostgreSQL to reject the write, but it succeeded.');
}

function stageFourCategory(): Category
{
    return Category::create([
        'name' => 'Stage four '.fake()->unique()->numerify('####'),
        'type' => 'expense',
    ]);
}

test('PostgreSQL enforces net worth entry and valuation constraints', function () {
    $user = User::factory()->create();
    $now = now();

    $base = [
        'user_id' => $user->id,
        'name' => 'Constraint account',
        'type' => 'asset',
        'category' => 'cash',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    DB::table('net_worth_entries')->insert(array_merge($base, ['name' => 'Valid cash']));

    stageFourExpectConstraint(fn () => DB::table('net_worth_entries')->insert(array_merge($base, [
        'name' => 'Bad type',
        'type' => 'equity',
    ])));
    stageFourExpectConstraint(fn () => DB::table('net_worth_entries')->insert(array_merge($base, [
        'name' => 'Bad combination',
        'type' => 'asset',
        'category' => 'loan',
    ])));
    stageFourExpectConstraint(fn () => DB::table('net_worth_entries')->insert(array_merge($base, [
        'name' => 'Bad status',
        'status' => 'deleted',
    ])));

    $entry = DB::table('net_worth_entries')->first();
    $valuation = [
        'net_worth_entry_id' => $entry->id,
        'value_amount' => 100,
        'valuation_date' => '2026-10-01',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    DB::table('net_worth_valuations')->insert($valuation);
    stageFourExpectConstraint(fn () => DB::table('net_worth_valuations')->insert($valuation));
    stageFourExpectConstraint(fn () => DB::table('net_worth_valuations')->insert(array_merge($valuation, [
        'value_amount' => -1,
        'valuation_date' => '2026-10-02',
    ])));

    expect(NetWorthEntry::count())->toBe(1)
        ->and(NetWorthValuation::count())->toBe(1);
});

test('PostgreSQL enforces subscription tracking shape and unique schedule links', function () {
    $user = User::factory()->create();
    $category = stageFourCategory();
    $now = now();

    $schedule = RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Constraint schedule',
        'amount' => 10,
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'status' => 'active',
        'reminder_days_before' => 3,
    ]);

    $standalone = [
        'user_id' => $user->id,
        'name' => 'Standalone tracker',
        'amount' => 10,
        'frequency' => 'monthly',
        'next_renewal_date' => '2026-11-01',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    DB::table('subscriptions')->insert($standalone);

    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($standalone, [
        'name' => 'No amount',
        'amount' => null,
    ])));
    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($standalone, [
        'name' => 'Bad status',
        'status' => 'archived',
    ])));
    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($standalone, [
        'name' => 'Bad frequency',
        'frequency' => 'fortnightly',
    ])));
    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($standalone, [
        'name' => 'Non-positive amount',
        'amount' => 0,
    ])));
    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($standalone, [
        'name' => 'Linked with competing values',
        'recurring_expense_id' => $schedule->id,
    ])));

    $linked = [
        'user_id' => $user->id,
        'name' => 'Linked tracker',
        'recurring_expense_id' => $schedule->id,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    DB::table('subscriptions')->insert($linked);
    stageFourExpectConstraint(fn () => DB::table('subscriptions')->insert(array_merge($linked, [
        'name' => 'Duplicate link',
    ])));

    stageFourExpectConstraint(fn () => DB::table('recurring_expenses')->where('id', $schedule->id)->delete());

    expect(Subscription::count())->toBe(2)
        ->and(RecurringExpense::count())->toBe(1);
});
