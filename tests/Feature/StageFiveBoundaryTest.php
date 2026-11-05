<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The ledger date columns are DATE columns, but SQLite stores them through
 * Eloquent's date cast as "Y-m-d H:i:s". A raw string comparison against a
 * bare "Y-m-d" upper bound therefore drops the last day of a range on SQLite
 * while PostgreSQL (a real DATE type) keeps it.
 *
 * These tests pin the behaviour so the report, the analytics endpoints and the
 * transaction search always count the same transactions on every supported
 * database:
 * - Inclusion of boundary days (first and last day of period) across SQLite and PostgreSQL.
 * - Budget alert spend calculation and threshold persistence on month boundary days
 *   (including final day of the month and adjacent month boundary isolation).
 * - Period resolution between default unparameterized requests (scoped to active clock)
 *   and explicit ?month= queries across month transitions.
 */
test('a transaction dated on the last day of the period is inside the period', function () {
    $user = User::factory()->create();
    $food = Category::create(['name' => 'Boundary Food', 'type' => 'expense']);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '10.00',
        'description' => 'Last day expense',
        'expense_date' => '2026-10-20',
    ]);
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '20.00',
        'description' => 'First day expense',
        'expense_date' => '2026-10-01',
    ]);

    $raw = DB::table('expenses')
        ->where('user_id', $user->id)
        ->whereBetween('expense_date', ['2026-10-01', '2026-10-20'])
        ->sum('amount');

    // The raw string comparison is the behaviour the report must not rely on.
    // On SQLite it drops the last day, because the stored value carries a time
    // component; on PostgreSQL the column is a real DATE, so it does not.
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect((float) $raw)->toBeLessThan(30.0);
    } else {
        expect((float) $raw)->toBe(30.0);
    }

    $report = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-20');
    $report->assertOk()->assertJsonPath('data.summary.expense_amount', '30.00');

    $search = $this->actingAs($user)->getJson('/api/transactions/search?type=expense&from=2026-10-01&to=2026-10-20');
    $search->assertOk()->assertJsonCount(2, 'data');
});

test('the report, the analytics endpoints and the search count the same transactions', function () {
    $user = User::factory()->create();
    $food = Category::create(['name' => 'Consistency Food', 'type' => 'expense']);

    // One transaction on each boundary day of the period.
    foreach (['2026-10-01' => '10.00', '2026-10-15' => '20.00', '2026-10-20' => '30.00'] as $date => $amount) {
        Expense::create([
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => $amount,
            'description' => 'Consistency expense',
            'expense_date' => $date,
        ]);
    }

    Income::create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'source' => 'Salary',
        'income_date' => '2026-10-20',
        'frequency' => 'one-time',
    ]);

    $report = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-20&compare=none');
    $report->assertOk()
        ->assertJsonPath('data.summary.expense_amount', '60.00')
        ->assertJsonPath('data.summary.income_amount', '100.00');

    $search = $this->actingAs($user)->getJson(
        '/api/transactions/search?from=2026-10-01&to=2026-10-20&per_page=100'
    );
    $search->assertOk()->assertJsonCount(4, 'data');

    $searchedExpenses = collect($search->json('data'))
        ->where('type', 'expense')
        ->sum(fn (array $row): float => (float) $row['amount']);
    expect($searchedExpenses)->toBe(60.0);

    $trends = $this->actingAs($user)->getJson(
        '/api/analytics/spending-trends?from=2026-10-01&to=2026-10-20&granularity=day'
    );
    $trends->assertOk()->assertJsonPath('data.summary.expenses_amount', '60.00');

    $quality = $this->actingAs($user)->getJson('/api/analytics/data-quality?from=2026-10&to=2026-10');
    $quality->assertOk();

    $income = $this->actingAs($user)->getJson('/api/analytics/income-analysis?from=2026-10&to=2026-10');
    $income->assertOk()->assertJsonPath('data.summary.actual_income_amount', '100.00');
});

test('the budget alert spend includes the last day of the month', function () {
    Carbon::setTestNow('2026-10-31 12:00:00');

    $user = User::factory()->create();
    $food = Category::create(['name' => 'Boundary Budget Food', 'type' => 'expense']);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-10-01',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '100.00',
        'description' => 'Last day of the month',
        'expense_date' => '2026-10-31',
    ]);

    $alerts = $this->actingAs($user)->getJson('/api/analytics/budget-alerts?year=2026&month=10');
    $alerts->assertOk();

    // The month is over its limit, so the persisted alert must exist when queried for October.
    $budgetAlerts = $this->actingAs($user)->getJson('/api/budget-alerts');
    $budgetAlerts->assertOk();
    expect($budgetAlerts->json('data.alerts'))->not->toBeEmpty();

    $explicitAlerts = $this->actingAs($user)->getJson('/api/budget-alerts?month=2026-10');
    $explicitAlerts->assertOk();
    expect($explicitAlerts->json('data.alerts'))->not->toBeEmpty();

    Carbon::setTestNow();
});

test('expenses on adjacent month boundaries are strictly excluded from current month budget alerts', function () {
    $user = User::factory()->create();
    $food = Category::create(['name' => 'Adjacent Month Boundary Food', 'type' => 'expense']);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-10-01',
        'alert_thresholds' => [50, 80, 100],
    ]);

    // Expense on the final day of the preceding month (September 30)
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '60.00',
        'description' => 'Preceding month end expense',
        'expense_date' => '2026-09-30',
    ]);

    // Expense on the first day of the subsequent month (November 1)
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '60.00',
        'description' => 'Subsequent month start expense',
        'expense_date' => '2026-11-01',
    ]);

    // Query October budget alerts: neither adjacent month expense should count toward October
    $octoberAlerts = $this->actingAs($user)->getJson('/api/budget-alerts?month=2026-10');
    $octoberAlerts->assertOk();
    expect($octoberAlerts->json('data.alerts'))->toBeEmpty();

    $octoberAnalytics = $this->actingAs($user)->getJson('/api/analytics/budget-alerts?year=2026&month=10');
    $octoberAnalytics->assertOk();
    expect($octoberAnalytics->json('data.alerts'))->toBeEmpty();

    // Now add transactions on October 1 (first day) and October 31 (last day) totaling exactly 50.00 (50% threshold)
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '25.00',
        'description' => 'October first day expense',
        'expense_date' => '2026-10-01',
    ]);
    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '25.00',
        'description' => 'October last day expense',
        'expense_date' => '2026-10-31',
    ]);

    $octoberAlertsAfter = $this->actingAs($user)->getJson('/api/budget-alerts?month=2026-10');
    $octoberAlertsAfter->assertOk();
    expect($octoberAlertsAfter->json('data.alerts'))->toHaveCount(1)
        ->and($octoberAlertsAfter->json('data.alerts.0.threshold'))->toBe(50)
        ->and($octoberAlertsAfter->json('data.alerts.0.spent_amount'))->toBe('50.00');
});

test('budget alerts queried across month transitions isolate alerts to their respective periods', function () {
    $user = User::factory()->create();
    $food = Category::create(['name' => 'Transition Boundary Food', 'type' => 'expense']);

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-10-01',
        'alert_thresholds' => [50, 80, 100],
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => '100.00',
        'description' => 'October full spend',
        'expense_date' => '2026-10-31',
    ]);

    // When the system clock is in October (e.g. 2026-10-31 23:59:59)
    Carbon::setTestNow('2026-10-31 23:59:59');
    $octoberAlerts = $this->actingAs($user)->getJson('/api/budget-alerts');
    $octoberAlerts->assertOk();
    expect($octoberAlerts->json('data.alerts'))->not->toBeEmpty()
        ->and($octoberAlerts->json('data.summary.period_month'))->toBe('2026-10');

    // When the system clock advances to the next month (2026-11-01 00:00:01)
    Carbon::setTestNow('2026-11-01 00:00:01');

    // Unparameterized endpoint defaults to the new month (November 2026), where no budget exists
    $novemberDefault = $this->actingAs($user)->getJson('/api/budget-alerts');
    $novemberDefault->assertOk();
    expect($novemberDefault->json('data.alerts'))->toBeEmpty()
        ->and($novemberDefault->json('data.summary.period_month'))->toBe('2026-11');

    // Explicit query for previous month continues to return October alerts accurately
    $octoberExplicit = $this->actingAs($user)->getJson('/api/budget-alerts?month=2026-10');
    $octoberExplicit->assertOk();
    expect($octoberExplicit->json('data.alerts'))->not->toBeEmpty()
        ->and($octoberExplicit->json('data.summary.period_month'))->toBe('2026-10');

    Carbon::setTestNow();
});
