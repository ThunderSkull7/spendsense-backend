<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurringExpenseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL constraint assertions require PostgreSQL.');
    }
});

function postgresBillCategory(): Category
{
    return Category::create(['name' => 'Constraint '.fake()->unique()->numerify('####'), 'type' => 'expense']);
}

test('PostgreSQL rejects a negative recurring expense amount', function () {
    $user = User::factory()->create();

    expect(fn () => RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Invalid amount',
        'amount' => -1,
        'category_id' => postgresBillCategory()->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-01',
        'status' => 'active',
        'reminder_days_before' => 3,
    ]))->toThrow(QueryException::class);
});

test('PostgreSQL rejects a reminder window outside zero to thirty days', function () {
    $user = User::factory()->create();

    expect(fn () => RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Invalid reminder',
        'amount' => 10,
        'category_id' => postgresBillCategory()->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-01',
        'status' => 'active',
        'reminder_days_before' => 31,
    ]))->toThrow(QueryException::class);
});

test('PostgreSQL rejects a paid occurrence without an expense link', function () {
    $user = User::factory()->create();
    $schedule = recurringExpenseForConstraints($user);

    expect(fn () => DB::table('recurring_expense_occurrences')
        ->where('id', $schedule->occurrences()->firstOrFail()->id)
        ->update(['status' => 'paid', 'paid_at' => now()])
    )->toThrow(QueryException::class);
});

test('PostgreSQL prevents one expense from backing two occurrences', function () {
    $user = User::factory()->create();
    $schedule = recurringExpenseForConstraints($user);
    $occurrences = $schedule->occurrences()->take(2)->get();
    $expense = Expense::create([
        'user_id' => $user->id,
        'category_id' => $schedule->category_id,
        'amount' => 100,
        'description' => 'Linked bill',
        'expense_date' => '2026-10-01',
    ]);

    DB::table('recurring_expense_occurrences')
        ->where('id', $occurrences[0]->id)
        ->update(['status' => 'paid', 'expense_id' => $expense->id, 'paid_at' => now()]);

    expect(fn () => DB::table('recurring_expense_occurrences')
        ->where('id', $occurrences[1]->id)
        ->update(['status' => 'paid', 'expense_id' => $expense->id, 'paid_at' => now()])
    )->toThrow(QueryException::class);
});

function recurringExpenseForConstraints(User $user): RecurringExpense
{
    $schedule = RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Linked bill',
        'amount' => 100,
        'category_id' => postgresBillCategory()->id,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
        'reminder_days_before' => 3,
    ]);
    app(RecurringExpenseService::class)->generateInitial($schedule);

    return $schedule;
}
