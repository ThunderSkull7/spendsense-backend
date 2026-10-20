<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringExpense;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalContribution;
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

/* ── Helpers ───────────────────────────────────────────────────────────── */

function stage5Category(string $name = 'Food', string $type = 'expense'): Category
{
    return Category::create(['name' => $name, 'type' => $type]);
}

function stage5Expense(User $user, Category $category, string $amount, string $date, ?string $description = 'Stage 5 expense'): Expense
{
    return Expense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => $amount,
        'description' => $description,
        'expense_date' => $date,
    ]);
}

function stage5Income(User $user, string $amount, string $date, string $source = 'Salary'): Income
{
    return Income::create([
        'user_id' => $user->id,
        'amount' => $amount,
        'source' => $source,
        'income_date' => $date,
        'frequency' => 'one-time',
    ]);
}

/* ── C: dashboard preferences ──────────────────────────────────────────── */

test('dashboard preferences return the default layout before anything is saved', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/dashboard/preferences');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_default', true);

    $keys = collect($response->json('data.widgets'))->pluck('key');
    expect($keys)->toContain('summary', 'spending_categories', 'net_worth');
});

test('dashboard preferences are saved per user and restored on the next read', function () {
    $user = User::factory()->create();

    $widgets = [
        ['key' => 'net_worth', 'visible' => true],
        ['key' => 'summary', 'visible' => true],
        ['key' => 'cash_flow', 'visible' => false],
    ];

    $this->actingAs($user)
        ->putJson('/api/dashboard/preferences', ['widgets' => $widgets])
        ->assertOk()
        ->assertJsonPath('data.is_default', false);

    $response = $this->actingAs($user)->getJson('/api/dashboard/preferences');

    $response->assertOk()
        ->assertJsonPath('data.widgets.0.key', 'net_worth')
        ->assertJsonPath('data.widgets.0.visible', true)
        ->assertJsonPath('data.widgets.1.key', 'summary')
        ->assertJsonPath('data.widgets.2.visible', false);
});

test('dashboard preferences are isolated between users', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->putJson('/api/dashboard/preferences', [
        'widgets' => [['key' => 'net_worth', 'visible' => true]],
    ])->assertOk();

    // The second user must not see the first user's layout.
    $this->actingAs($second)
        ->getJson('/api/dashboard/preferences')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);
});

test('dashboard preferences reject unknown and duplicated widget keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/dashboard/preferences', [
            'widgets' => [['key' => 'not_a_widget', 'visible' => true]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('widgets.0.key');

    $this->actingAs($user)
        ->putJson('/api/dashboard/preferences', [
            'widgets' => [
                ['key' => 'summary', 'visible' => true],
                ['key' => 'summary', 'visible' => false],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('widgets.1.key');
});

test('dashboard preferences require authentication', function () {
    $this->getJson('/api/dashboard/preferences')->assertStatus(401);
    $this->putJson('/api/dashboard/preferences', [
        'widgets' => [['key' => 'summary', 'visible' => true]],
    ])->assertStatus(401);
    $this->deleteJson('/api/dashboard/preferences')->assertStatus(401);
});

test('resetting dashboard preferences restores the default layout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/dashboard/preferences', [
        'widgets' => [['key' => 'net_worth', 'visible' => true]],
    ])->assertOk();

    $this->actingAs($user)
        ->deleteJson('/api/dashboard/preferences')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    $this->actingAs($user)
        ->getJson('/api/dashboard/preferences')
        ->assertOk()
        ->assertJsonPath('data.is_default', true);
});

/* ── D: server-side transaction search ─────────────────────────────────── */

test('transaction search filters by type description date and amount', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');
    $rent = stage5Category('Rent');

    stage5Expense($user, $food, '200.00', '2026-10-02', 'Lunch');
    stage5Expense($user, $rent, '900.00', '2026-09-20', 'Rent');
    stage5Income($user, '3000.00', '2026-10-03', 'Salary');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?q=lunch')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Lunch')
        ->assertJsonPath('data.0.type', 'expense');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?type=income')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'income');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?min_amount=500&max_amount=1000')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', '900.00');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?category_id='.$rent->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category_name', 'Rent');
});

test('transaction search paginates deterministically and reports the totals', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    foreach (range(1, 12) as $index) {
        stage5Expense($user, $food, '10.00', sprintf('2026-10-%02d', $index), 'Item '.$index);
    }

    $first = $this->actingAs($user)->getJson('/api/transactions/search?per_page=10&page=1');
    $first->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 12)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.current_page', 1);

    $second = $this->actingAs($user)->getJson('/api/transactions/search?per_page=10&page=2');
    $second->assertOk()->assertJsonCount(2, 'data');

    // Pages must not overlap.
    $firstIds = collect($first->json('data'))->pluck('id');
    $secondIds = collect($second->json('data'))->pluck('id');
    expect($firstIds->intersect($secondIds))->toBeEmpty();

    // The same sort must return the same order every time.
    $repeat = $this->actingAs($user)->getJson('/api/transactions/search?per_page=10&page=1&sort=date_desc');
    expect(collect($repeat->json('data'))->pluck('id')->all())->toBe($firstIds->all());
});

test('transaction search excludes soft deleted rows and other users rows', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '50.00', '2026-10-05', 'Kept');
    $deleted = stage5Expense($user, $food, '60.00', '2026-10-06', 'Removed');
    $deleted->delete();
    stage5Expense($other, $food, '70.00', '2026-10-07', 'Theirs');

    $response = $this->actingAs($user)->getJson('/api/transactions/search');
    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Kept');
});

test('transaction search validates its filters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/transactions/search?from=2026-10-31&to=2026-10-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?min_amount=100&max_amount=10')
        ->assertStatus(422)
        ->assertJsonValidationErrors('max_amount');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?type=income&category_id=1')
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_id');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?sort=not_a_sort')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    $this->actingAs($user)
        ->getJson('/api/transactions/search?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('transaction search requires authentication', function () {
    $this->getJson('/api/transactions/search')->assertStatus(401);
});

test('the transaction CSV export matches the search results and neutralises formulas', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '120.00', '2026-10-02', '=cmd|calc!A1');
    stage5Expense($user, $food, '80.00', '2026-10-03', '@SUM(A1)');

    $response = $this->actingAs($user)->get('/api/transactions/export?type=expense');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Type,Date,Description');

    // A leading = or @ must be neutralised so a spreadsheet cannot execute it.
    expect($csv)->toContain("'=cmd|calc!A1");
    expect($csv)->toContain("'@SUM(A1)");

    // The amounts themselves must stay numeric so the export remains usable.
    expect($csv)->toContain('120.00');
    expect($csv)->toContain('-120.00');
});

/* ── B: the reports centre ─────────────────────────────────────────────── */

test('the report totals only the actual transactions in the selected period', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Income($user, '3000.00', '2026-10-01');
    stage5Expense($user, $food, '200.00', '2026-10-05');
    stage5Expense($user, $food, '100.00', '2026-10-06');
    // Outside the reported window.
    stage5Expense($user, $food, '999.00', '2026-09-01');

    $response = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-31');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.income_amount', '3000.00')
        ->assertJsonPath('data.summary.expense_amount', '300.00')
        ->assertJsonPath('data.summary.net_amount', '2700.00')
        ->assertJsonPath('data.summary.income_count', 1)
        ->assertJsonPath('data.summary.expense_count', 2)
        ->assertJsonPath('data.period.timezone', 'UTC');
});

test('the report separates actuals from expected obligations and reports no balance', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '200.00', '2026-10-05');

    $response = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-31');

    $response->assertOk()
        ->assertJsonPath('data.balances.opening.status', 'not_available')
        ->assertJsonPath('data.balances.opening.amount', null)
        ->assertJsonPath('data.balances.ending.status', 'not_available')
        ->assertJsonPath('data.summary.expense_amount', '200.00');

    // The expected bill total is reported separately and never folded into
    // the actual expense total.
    expect($response->json('data.expectations.upcoming_bills_amount'))->not->toBeNull();
    expect($response->json('data.summary.expense_amount'))->toBe('200.00');
});

test('the report compares the selected period with the previous one', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '100.00', '2026-09-10');
    stage5Expense($user, $food, '300.00', '2026-10-10');

    $response = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-31&compare=previous');

    $response->assertOk()
        ->assertJsonPath('data.comparison.current.expense_amount', '300.00')
        ->assertJsonPath('data.comparison.previous.expense_amount', '100.00')
        ->assertJsonPath('data.comparison.changes.expense.amount', '200.00')
        ->assertJsonPath('data.comparison.changes.expense.percent', 200)
        ->assertJsonPath('data.comparison_period.from', '2026-09-01')
        ->assertJsonPath('data.comparison_period.to', '2026-09-30');
});

test('the report can omit sections and refuses an unknown one', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');
    stage5Expense($user, $food, '100.00', '2026-10-05');

    $this->actingAs($user)
        ->getJson('/api/reports?from=2026-10-01&to=2026-10-31&sections=categories')
        ->assertOk()
        ->assertJsonPath('data.budgets', null)
        ->assertJsonCount(1, 'data.categories');

    $this->actingAs($user)
        ->getJson('/api/reports?from=2026-10-01&to=2026-10-31&sections=not_a_section')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sections');
});

test('the report is owner scoped and never exposes another account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '250.00', '2026-10-05', 'Mine');
    stage5Expense($other, $food, '750.00', '2026-10-05', 'Theirs');

    $response = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-31');
    $response->assertOk()
        ->assertJsonPath('data.summary.expense_amount', '250.00')
        ->assertJsonCount(1, 'data.categories');
});

test('the report CSV export contains the same totals as the preview', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Income($user, '3000.00', '2026-10-01');
    stage5Expense($user, $food, '200.00', '2026-10-05');

    $preview = $this->actingAs($user)->getJson('/api/reports?from=2026-10-01&to=2026-10-31');
    $preview->assertOk();

    $export = $this->actingAs($user)->get('/api/reports/export?from=2026-10-01&to=2026-10-31');
    $export->assertOk();
    $csv = $export->streamedContent();

    expect($csv)->toContain('SpendSense financial report');
    expect($csv)->toContain('Income');
    expect($csv)->toContain($preview->json('data.summary.income_amount'));
    expect($csv)->toContain($preview->json('data.summary.expense_amount'));
    expect($csv)->toContain('Opening balance');
    expect($csv)->toContain('Not available');
});

test('the report requires authentication', function () {
    $this->getJson('/api/reports')->assertStatus(401);
    $this->get('/api/reports/export')->assertStatus(401);
});

/* ── A: the analytics endpoints ────────────────────────────────────────── */

test('the spending trend endpoint buckets by day week or month and compares periods', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '100.00', '2026-10-01');
    stage5Expense($user, $food, '200.00', '2026-10-02');
    stage5Income($user, '500.00', '2026-10-02');
    // Inside the previous period, which is the seven days before the range.
    stage5Expense($user, $food, '150.00', '2026-09-25');

    $daily = $this->actingAs($user)->getJson('/api/analytics/spending-trends?from=2026-10-01&to=2026-10-07&granularity=day');
    $daily->assertOk()
        ->assertJsonPath('data.granularity', 'day')
        ->assertJsonPath('data.summary.expenses_amount', '300.00')
        ->assertJsonPath('data.summary.income_amount', '500.00')
        ->assertJsonPath('data.comparison.expenses.previous_amount', '150.00');

    $weekly = $this->actingAs($user)->getJson('/api/analytics/spending-trends?from=2026-10-01&to=2026-10-07&granularity=week');
    $weekly->assertOk()->assertJsonPath('data.granularity', 'week');

    $monthly = $this->actingAs($user)->getJson('/api/analytics/spending-trends?from=2026-09-01&to=2026-10-31&granularity=month');
    $monthly->assertOk()->assertJsonPath('data.granularity', 'month');

    $this->actingAs($user)
        ->getJson('/api/analytics/spending-trends?from=2026-10-01&to=2026-10-07&granularity=decade')
        ->assertStatus(422)
        ->assertJsonValidationErrors('granularity');
});

test('the spending trend endpoint refuses a range that is too long for a daily trend', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/analytics/spending-trends?from=2020-01-01&to=2026-10-07&granularity=day')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

test('the income analysis separates actual income from expected scheduled income', function () {
    $user = User::factory()->create();

    stage5Income($user, '3000.00', '2026-10-01', 'Salary');
    stage5Income($user, '500.00', '2026-10-05', 'Freelance');

    $response = $this->actingAs($user)->getJson('/api/analytics/income-analysis?from=2026-10&to=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.summary.actual_income_amount', '3500.00')
        ->assertJsonPath('data.summary.scheduled_expected_amount', '0.00')
        ->assertJsonPath('data.summary.unplanned_actual_amount', '3500.00')
        ->assertJsonPath('data.summary.source_count', 2);
});

test('the budget performance endpoint reports utilisation thresholds and repeat overspending', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-09-01',
    ]);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'limit_amount' => '100.00',
        'month_year' => '2026-10-01',
    ]);

    stage5Expense($user, $food, '150.00', '2026-09-10');
    stage5Expense($user, $food, '160.00', '2026-10-10');

    $response = $this->actingAs($user)->getJson('/api/analytics/budget-performance?from=2026-09&to=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.summary.overspent_rows', 2)
        ->assertJsonPath('data.summary.budgeted_amount', '200.00')
        ->assertJsonPath('data.summary.spent_amount', '310.00')
        ->assertJsonCount(1, 'data.repeated_overspending')
        ->assertJsonPath('data.repeated_overspending.0.overspent_months', 2);
});

test('the savings progress endpoint reports goal progress and keeps archived goals out of the totals', function () {
    $user = User::factory()->create();

    $goal = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Laptop',
        'target_amount' => '1000.00',
        'target_date' => '2026-12-31',
        'status' => 'active',
    ]);
    SavingsGoalContribution::create([
        'savings_goal_id' => $goal->id,
        'type' => 'contribution',
        'amount' => '250.00',
        'contributed_at' => '2026-10-05',
    ]);

    $archived = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Old goal',
        'target_amount' => '500.00',
        'status' => 'archived',
        'archived_at' => now(),
    ]);
    SavingsGoalContribution::create([
        'savings_goal_id' => $archived->id,
        'type' => 'contribution',
        'amount' => '500.00',
        'contributed_at' => '2026-09-05',
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/savings-progress?from=2026-09&to=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.summary.active_goal_count', 1)
        ->assertJsonPath('data.summary.archived_goal_count', 1)
        ->assertJsonPath('data.summary.total_saved_active_amount', '250.00')
        ->assertJsonPath('data.summary.total_target_amount', '1000.00')
        ->assertJsonPath('data.summary.period_net_amount', '250.00');
});

test('the commitment endpoint counts a linked subscription exactly once', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    $schedule = RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Streaming',
        'amount' => '120.00',
        'category_id' => $food->id,
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);

    // A linked tracker derives its amount, frequency and renewal date from
    // the schedule, so it must not carry its own financial fields. The
    // PostgreSQL tracking_shape constraint enforces exactly this.
    Subscription::create([
        'user_id' => $user->id,
        'name' => 'Streaming tracker',
        'provider' => 'Example',
        'recurring_expense_id' => $schedule->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->getJson('/api/analytics/commitments?from=2026-10&to=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.summary.bill_schedule_monthly_cost', '120.00')
        ->assertJsonPath('data.summary.standalone_subscription_monthly_cost', '0.00')
        ->assertJsonPath('data.summary.linked_subscription_monthly_cost', '120.00')
        // The combined total must equal the bill schedule total, not the sum.
        ->assertJsonPath('data.summary.combined_monthly_commitment', '120.00')
        ->assertJsonPath('data.summary.active_schedule_count', 1);
});

test('the data quality endpoint reports review signals without changing any record', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '50.00', '2026-10-02', 'Duplicate');
    stage5Expense($user, $food, '50.00', '2026-10-02', 'Duplicate');
    stage5Expense($user, $food, '60.00', '2026-10-03', null);

    $before = Expense::where('user_id', $user->id)->count();

    $response = $this->actingAs($user)->getJson('/api/analytics/data-quality?from=2026-10&to=2026-10');

    $response->assertOk()
        ->assertJsonPath('data.counts.duplicate_expense_groups', 1)
        ->assertJsonPath('data.counts.duplicate_expense_rows', 2)
        ->assertJsonPath('data.counts.missing_descriptions', 1)
        ->assertJsonPath('data.import_validation.status', 'not_available');

    expect(Expense::where('user_id', $user->id)->count())->toBe($before);
});

test('every analytics endpoint is owner scoped', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($other, $food, '400.00', '2026-10-05');

    $endpoints = [
        '/api/analytics/spending-trends?from=2026-10-01&to=2026-10-31&granularity=day',
        '/api/analytics/income-analysis?from=2026-10&to=2026-10',
        '/api/analytics/budget-performance?from=2026-10&to=2026-10',
        '/api/analytics/savings-progress?from=2026-10&to=2026-10',
        '/api/analytics/commitments?from=2026-10&to=2026-10',
        '/api/analytics/data-quality?from=2026-10&to=2026-10',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->actingAs($user)->getJson($endpoint);
        $response->assertOk();

        // No endpoint may report another account's spending.
        $body = $response->json('data');
        expect(json_encode($body))->not->toContain('400.00');
    }
});

test('the analytics endpoints require authentication', function () {
    $endpoints = [
        '/api/analytics/spending-trends',
        '/api/analytics/income-analysis',
        '/api/analytics/budget-performance',
        '/api/analytics/savings-progress',
        '/api/analytics/commitments',
        '/api/analytics/data-quality',
    ];

    foreach ($endpoints as $endpoint) {
        $this->getJson($endpoint)->assertStatus(401);
    }
});

test('the full data export contains only the authenticated account rows', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $food = stage5Category('Food');

    stage5Expense($user, $food, '120.00', '2026-10-05', 'Mine');
    $deleted = stage5Expense($user, $food, '130.00', '2026-10-06', 'Removed');
    $deleted->delete();
    stage5Income($user, '900.00', '2026-10-01', 'Salary');
    stage5Expense($other, $food, '999.00', '2026-10-07', 'Theirs');

    $response = $this->actingAs($user)->getJson('/api/export/all');
    $response->assertOk()
        ->assertJsonPath('meta.format', 'spendsense-user-data')
        ->assertJsonPath('meta.counts.expenses', 2);

    $body = $response->json();
    expect(json_encode($body))->not->toContain('Theirs');
    expect(json_encode($body))->not->toContain('999.00');
    // A soft-deleted expense is still exported, with its deleted_at marker.
    expect($body['expenses'])->toHaveCount(2);
    $removed = collect($body['expenses'])->firstWhere('description', 'Removed');
    expect($removed['deleted_at'])->not->toBeNull();

    // No credential material may appear in the export. The notes explain
    // what is excluded, so the check is on the exported keys, not the text.
    expect(array_keys($body['account']))->not->toContain('password');
    expect(array_keys($body['account']))->not->toContain('remember_token');
    expect(array_keys($body))->not->toContain('idempotency_records');
    expect(array_keys($body))->not->toContain('sessions');
    expect($body['account'])->not->toHaveKey('password');
});

test('the full data export requires authentication', function () {
    $this->getJson('/api/export/all')->assertStatus(401);
});

test('the quick add endpoints reuse the existing idempotent create contract', function () {
    $user = User::factory()->create();
    $food = stage5Category('Food');

    $payload = [
        'amount' => 25.5,
        'category_id' => $food->id,
        'expense_date' => '2026-10-20',
        'description' => 'Quick add',
    ];

    $first = $this->actingAs($user)->withHeader('Idempotency-Key', 'stage5-quick-add-1')
        ->postJson('/api/expenses', $payload);
    $first->assertCreated();

    // The same key with the same payload replays the original response.
    $second = $this->actingAs($user)->withHeader('Idempotency-Key', 'stage5-quick-add-1')
        ->postJson('/api/expenses', $payload);
    $second->assertCreated();

    expect(Expense::where('user_id', $user->id)->count())->toBe(1);
});
