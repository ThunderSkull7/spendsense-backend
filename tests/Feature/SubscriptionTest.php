<?php

use App\Models\Category;
use App\Models\Expense;
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

function subscriptionCategory(string $name = 'Subscriptions', ?User $owner = null): Category
{
    return Category::create([
        'name' => $name,
        'type' => 'expense',
        'user_id' => $owner?->id,
        'is_default' => $owner === null,
    ]);
}

function subscriptionPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Streaming service',
        'provider' => 'Example provider',
        'amount' => '9.99',
        'frequency' => 'monthly',
        'next_renewal_date' => '2026-11-02',
        'category_id' => null,
        'status' => 'active',
        'notes' => 'Tracked manually',
    ], $overrides);
}

function createSubscription($test, User $user, array $overrides = []): array
{
    return $test->actingAs($user)
        ->postJson('/api/subscriptions', subscriptionPayload($overrides))
        ->assertCreated()
        ->json('data');
}

function createSubscriptionSchedule($test, User $user, Category $category, array $overrides = []): array
{
    return $test->actingAs($user)->postJson('/api/recurring-expenses', array_merge([
        'description' => 'Quarterly service',
        'amount' => '30.00',
        'category_id' => $category->id,
        'frequency' => 'quarterly',
        'start_date' => '2026-10-05',
    ], $overrides))->assertCreated()->json('data');
}

test('standalone subscription estimates are normalized with safe rounding', function () {
    $user = User::factory()->create();

    $monthly = createSubscription($this, $user, ['name' => 'Monthly', 'amount' => '9.99']);
    $yearly = createSubscription($this, $user, ['name' => 'Yearly', 'amount' => '120.00', 'frequency' => 'yearly']);
    $weekly = createSubscription($this, $user, ['name' => 'Weekly', 'amount' => '10.00', 'frequency' => 'weekly']);

    expect($monthly['monthly_cost'])->toBe('9.99')
        ->and($monthly['annual_cost'])->toBe('119.88')
        ->and($yearly['monthly_cost'])->toBe('10.00')
        ->and($yearly['annual_cost'])->toBe('120.00')
        ->and($weekly['monthly_cost'])->toBe('43.33')
        ->and($weekly['annual_cost'])->toBe('520.00');

    $this->actingAs($user)->getJson('/api/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.summary.active_count', 3)
        ->assertJsonPath('data.summary.estimated_monthly_cost', '63.32')
        ->assertJsonPath('data.summary.estimated_annual_cost', '759.88')
        ->assertJsonPath('data.summary.timezone', 'UTC');
});

test('a linked subscription reads one schedule and is counted exactly once', function () {
    $user = User::factory()->create();
    $category = subscriptionCategory('Internet');
    $schedule = createSubscriptionSchedule($this, $user, $category);

    $linked = $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Home internet',
        'provider' => 'ISP',
        'recurring_expense_id' => $schedule['id'],
        'status' => 'active',
    ])->assertCreated()->json('data');

    expect($linked['amount'])->toBe('30.00')
        ->and($linked['frequency'])->toBe('quarterly')
        ->and($linked['monthly_cost'])->toBe('10.00')
        ->and($linked['annual_cost'])->toBe('120.00')
        ->and($linked['category']['name'])->toBe('Internet')
        ->and($linked['is_linked'])->toBe(true)
        ->and($linked['counts_toward_totals'])->toBe(true)
        ->and($linked['next_renewal_date'])->not->toBeNull();

    $row = Subscription::findOrFail($linked['id']);
    expect($row->amount)->toBeNull()
        ->and($row->frequency)->toBeNull()
        ->and($row->category_id)->toBeNull();

    $this->actingAs($user)->getJson('/api/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.summary.linked_count', 1)
        ->assertJsonPath('data.summary.standalone_count', 0)
        ->assertJsonPath('data.summary.estimated_monthly_cost', '10.00')
        ->assertJsonPath('data.summary.estimated_annual_cost', '120.00');

    // The same schedule cannot be linked twice.
    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Duplicate tracker',
        'recurring_expense_id' => $schedule['id'],
    ])->assertStatus(409);

    // Linked schedule values are immutable through the tracker.
    $this->actingAs($user)->putJson("/api/subscriptions/{$linked['id']}", [
        'amount' => '99.00',
    ])->assertStatus(422);
    $this->actingAs($user)->putJson("/api/subscriptions/{$linked['id']}", [
        'recurring_expense_id' => $schedule['id'],
    ])->assertStatus(422);

    $this->actingAs($user)->putJson("/api/subscriptions/{$linked['id']}", [
        'name' => 'Renamed tracker',
        'status' => 'paused',
        'notes' => 'Changed in tracker only',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Renamed tracker')
        ->assertJsonPath('data.status', 'paused')
        ->assertJsonPath('data.effective_status', 'paused')
        ->assertJsonPath('data.counts_toward_totals', false);

    expect(RecurringExpense::find($schedule['id'])->status)->toBe('active');
    expect(Subscription::count())->toBe(1);
});

test('linked subscription status follows paused and stopped schedules without changing them', function () {
    $user = User::factory()->create();
    $category = subscriptionCategory();
    $schedule = createSubscriptionSchedule($this, $user, $category);

    $linked = $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Linked service',
        'recurring_expense_id' => $schedule['id'],
    ])->assertCreated()->json('data');

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule['id']}/pause")->assertOk();
    $this->actingAs($user)->getJson("/api/subscriptions/{$linked['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.schedule_status', 'paused')
        ->assertJsonPath('data.effective_status', 'paused')
        ->assertJsonPath('data.counts_toward_totals', false);

    $this->actingAs($user)->getJson('/api/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.summary.active_count', 0)
        ->assertJsonPath('data.summary.paused_count', 1)
        ->assertJsonPath('data.summary.estimated_monthly_cost', '0.00');

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule['id']}/resume")->assertOk();
    $this->actingAs($user)->getJson("/api/subscriptions/{$linked['id']}")
        ->assertOk()
        ->assertJsonPath('data.effective_status', 'active')
        ->assertJsonPath('data.counts_toward_totals', true);

    RecurringExpense::whereKey($schedule['id'])->update(['status' => 'stopped']);
    $this->actingAs($user)->getJson("/api/subscriptions/{$linked['id']}")
        ->assertOk()
        ->assertJsonPath('data.effective_status', 'cancelled')
        ->assertJsonPath('data.counts_toward_totals', false);
});

test('subscription search filters statuses and upcoming renewals', function () {
    $user = User::factory()->create();
    $category = subscriptionCategory();

    createSubscription($this, $user, [
        'name' => 'Netflix',
        'provider' => 'Netflix Inc',
        'next_renewal_date' => '2026-10-15',
        'category_id' => $category->id,
    ]);
    createSubscription($this, $user, [
        'name' => 'Gym',
        'provider' => 'Local fitness',
        'next_renewal_date' => '2026-11-01',
        'status' => 'paused',
    ]);
    createSubscription($this, $user, [
        'name' => 'News',
        'provider' => 'Daily paper',
        'next_renewal_date' => '2026-11-05',
        'status' => 'cancelled',
    ]);
    createSubscription($this, $user, [
        'name' => 'Music',
        'provider' => 'Streaming',
        'amount' => '5.00',
        'next_renewal_date' => '2026-11-05',
    ]);
    $schedule = createSubscriptionSchedule($this, $user, $category, [
        'description' => 'Quarterly internet',
    ]);
    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Internet',
        'recurring_expense_id' => $schedule['id'],
    ])->assertCreated();

    $this->actingAs($user)->getJson('/api/subscriptions?search=netflix')
        ->assertOk()
        ->assertJsonCount(1, 'data.subscriptions')
        ->assertJsonPath('data.subscriptions.0.name', 'Netflix');

    $this->actingAs($user)->getJson('/api/subscriptions?status=paused')
        ->assertOk()
        ->assertJsonCount(1, 'data.subscriptions')
        ->assertJsonPath('data.subscriptions.0.name', 'Gym');

    $this->actingAs($user)->getJson('/api/subscriptions?link=linked')
        ->assertOk()
        ->assertJsonCount(1, 'data.subscriptions')
        ->assertJsonPath('data.subscriptions.0.name', 'Internet');

    $response = $this->actingAs($user)->getJson('/api/subscriptions?as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.summary.active_count', 3)
        ->assertJsonPath('data.summary.due_within_30_days_count', 1)
        ->assertJsonPath('data.summary.due_within_30_days_amount', '5.00')
        ->assertJsonPath('data.summary.overdue_count', 1);

    $upcoming = $response->json('data.upcoming_renewals');
    expect($upcoming[0]['name'])->toBe('Netflix')
        ->and($upcoming[0]['is_overdue'])->toBe(true)
        ->and($upcoming[0]['days_until_renewal'])->toBeLessThan(0);
    expect($upcoming[1]['name'])->toBe('Music')
        ->and($upcoming[1]['is_overdue'])->toBe(false);
    expect($response->json('data.notes'))->toContain(
        'SpendSense cannot cancel a subscription with a provider; this page is a manual tracker.'
    );
});

test('subscription validation ownership and financial side effects are enforced', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = subscriptionCategory();
    $incomeCategory = Category::query()->firstOrCreate(
        ['name' => 'Salary', 'type' => 'income', 'user_id' => null],
        ['is_default' => true]
    );
    $otherSchedule = createSubscriptionSchedule($this, $other, subscriptionCategory('Other category'));

    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Missing fields',
    ])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', subscriptionPayload([
        'amount' => '0.00',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', subscriptionPayload([
        'frequency' => 'every-second-tuesday',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', subscriptionPayload([
        'next_renewal_date' => '2026-13-01',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', subscriptionPayload([
        'category_id' => $incomeCategory->id,
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Linked with competing fields',
        'recurring_expense_id' => $otherSchedule['id'],
        'amount' => '5.00',
    ])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Foreign schedule',
        'recurring_expense_id' => $otherSchedule['id'],
    ])->assertStatus(422);
    $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Missing schedule',
        'recurring_expense_id' => 999999,
    ])->assertStatus(422);

    $beforeExpenses = Expense::count();
    $owner = createSubscription($this, $user, [
        'category_id' => $category->id,
    ]);
    $this->actingAs($user)->putJson("/api/subscriptions/{$owner['id']}", [
        'amount' => '12.50',
        'frequency' => 'yearly',
        'next_renewal_date' => '2026-12-01',
        'status' => 'active',
    ])->assertOk()
        ->assertJsonPath('data.amount', '12.50')
        ->assertJsonPath('data.frequency', 'yearly')
        ->assertJsonPath('data.monthly_cost', '1.04')
        ->assertJsonPath('data.annual_cost', '12.50');

    expect(Expense::count())->toBe($beforeExpenses);
    expect(RecurringExpense::count())->toBe(1);

    // Another user's records are not readable or writable through the owner's session.
    $this->actingAs($other)->getJson("/api/subscriptions/{$owner['id']}")->assertStatus(403);
    $this->actingAs($other)->putJson("/api/subscriptions/{$owner['id']}", ['name' => 'Hijack'])->assertStatus(403);
    $this->actingAs($user)->getJson('/api/subscriptions/999999')->assertStatus(404);

    auth()->forgetGuards();
    $this->getJson('/api/subscriptions')->assertStatus(401);
    $this->postJson('/api/subscriptions', subscriptionPayload())->assertStatus(401);
});

test('a category used by a subscription tracker cannot change type or be deleted', function () {
    $user = User::factory()->create();
    $category = subscriptionCategory('Tracked category', $user);
    createSubscription($this, $user, ['category_id' => $category->id]);

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", [
            'name' => $category->name,
            'type' => 'income',
        ])
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Cannot change category type: category has associated subscription trackers.'
        );

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Cannot delete category: category has associated subscription trackers.'
        );

    expect($category->fresh()->type)->toBe('expense');
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('cancelling a tracker preserves paid history and never touches the schedule', function () {
    $user = User::factory()->create();
    $category = subscriptionCategory();
    $schedule = createSubscriptionSchedule($this, $user, $category, [
        'frequency' => 'monthly',
        'start_date' => '2026-10-20',
    ]);

    $occurrence = RecurringExpenseOccurrence::where('recurring_expense_id', $schedule['id'])->firstOrFail();
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule['id']}/occurrences/{$occurrence->id}/pay",
        ['actual_amount' => '30.00', 'paid_date' => '2026-10-20']
    )->assertOk();

    $linked = $this->actingAs($user)->postJson('/api/subscriptions', [
        'name' => 'Linked service',
        'recurring_expense_id' => $schedule['id'],
    ])->assertCreated()->json('data');

    $expenseCount = Expense::count();
    $this->actingAs($user)->putJson("/api/subscriptions/{$linked['id']}", [
        'status' => 'cancelled',
    ])->assertOk()
        ->assertJsonPath('data.effective_status', 'cancelled')
        ->assertJsonPath('data.counts_toward_totals', false);

    expect(Expense::count())->toBe($expenseCount)
        ->and(Expense::first()->description)->toContain('Quarterly service');
    expect(RecurringExpense::find($schedule['id'])->status)->toBe('active');
    expect(RecurringExpenseOccurrence::where('recurring_expense_id', $schedule['id'])->where('status', 'paid')->count())->toBe(1);
});

test('subscription endpoints require authentication', function () {
    $this->getJson('/api/subscriptions')->assertStatus(401);
    $this->postJson('/api/subscriptions', subscriptionPayload())->assertStatus(401);
});
