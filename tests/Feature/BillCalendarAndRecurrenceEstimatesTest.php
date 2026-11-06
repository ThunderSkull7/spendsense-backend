<?php

use App\Models\Category;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurrenceDateService;
use App\Support\FrequencyCost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-06');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('frequency cost calculates exact normalized monthly and annual estimates', function () {
    // Weekly: 52 * 100 = 5200 annual, 5200 / 12 = 433.33 -> 433 cents ($4.33)
    expect(FrequencyCost::annualCents('1.00', 'weekly'))->toBe(5200);
    expect(FrequencyCost::monthlyCents('1.00', 'weekly'))->toBe(433);

    // Monthly: 12 * 1000 = 12000 annual, 1000 monthly
    expect(FrequencyCost::annualCents('10.00', 'monthly'))->toBe(12000);
    expect(FrequencyCost::monthlyCents('10.00', 'monthly'))->toBe(1000);

    // Quarterly: 4 * 3000 = 12000 annual, 1000 monthly
    expect(FrequencyCost::annualCents('30.00', 'quarterly'))->toBe(12000);
    expect(FrequencyCost::monthlyCents('30.00', 'quarterly'))->toBe(1000);

    // Yearly: 1 * 12000 = 12000 annual, 1000 monthly
    expect(FrequencyCost::annualCents('120.00', 'yearly'))->toBe(12000);
    expect(FrequencyCost::monthlyCents('120.00', 'yearly'))->toBe(1000);

    // Daily: 365 * 100 = 36500 annual, 36500 / 12 = 3041.67 -> 3042 cents ($30.42)
    expect(FrequencyCost::annualCents('1.00', 'daily'))->toBe(36500);
    expect(FrequencyCost::monthlyCents('1.00', 'daily'))->toBe(3042);

    // Biweekly: 26 * 1000 = 26000 annual, 26000 / 12 = 2166.67 -> 2167 cents ($21.67)
    expect(FrequencyCost::annualCents('10.00', 'biweekly'))->toBe(26000);
    expect(FrequencyCost::monthlyCents('10.00', 'biweekly'))->toBe(2167);

    // Half-yearly: 2 * 6000 = 12000 annual, 1000 monthly
    expect(FrequencyCost::annualCents('60.00', 'half-yearly'))->toBe(12000);
    expect(FrequencyCost::monthlyCents('60.00', 'half-yearly'))->toBe(1000);
});

test('recurring expense index returns monthly and annual equivalent estimates in summary and schedule payloads', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'user_id' => $user->id,
        'name' => 'Bills',
        'type' => 'expense',
    ]);

    // Create a monthly expense of 100.00
    RecurringExpense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'description' => 'Internet',
        'amount' => '100.00',
        'frequency' => 'monthly',
        'start_date' => '2026-11-01',
        'status' => 'active',
        'reminder_days_before' => 3,
    ]);

    // Create a weekly expense of 25.00
    RecurringExpense::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'description' => 'Groceries Subs',
        'amount' => '25.00',
        'frequency' => 'weekly',
        'start_date' => '2026-11-01',
        'status' => 'active',
        'reminder_days_before' => 2,
    ]);

    $response = $this->actingAs($user)->getJson('/api/recurring-expenses');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.active_count', 2);

    // 100 monthly = 100.00/mo, 1200.00/yr
    // 25 weekly = 25 * 52 / 12 = 108.33/mo, 1300.00/yr
    // Combined monthly = 208.33
    // Combined annual = 2500.00
    $data = $response->json('data');
    expect($data['summary']['monthly_equivalent_total'])->toBe('208.33');
    expect($data['summary']['annual_equivalent_total'])->toBe('2500.00');

    $schedules = $data['schedules'];
    expect($schedules)->toHaveCount(2);
});

test('bill calendar handles month boundaries, leap years, and filters', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'user_id' => $user->id,
        'name' => 'Utilities',
        'type' => 'expense',
    ]);

    // Create a monthly expense started on 31st Jan 2028 (leap year)
    $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Gym Leap',
        'amount' => '50.00',
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2028-01-31',
        'end_date' => '2028-04-30',
        'reminder_days_before' => 3,
    ])->assertStatus(201);

    // Check February 2028 calendar: 2028 is a leap year, so 31st January anchor lands on 29th February!
    $calFeb = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2028-02');
    $calFeb->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.month', '2028-02')
        ->assertJsonPath('data.days_in_month', 29)
        ->assertJsonPath('data.first_weekday', 2) // Tuesday
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.occurrence_date', '2028-02-29')
        ->assertJsonPath('data.entries.0.expected_amount', '50.00');

    // Check April 2028 calendar: 30 days in April, lands on 2028-04-30
    $calApr = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2028-04');
    $calApr->assertStatus(200)
        ->assertJsonPath('data.days_in_month', 30)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.occurrence_date', '2028-04-30');
});

test('paying a bill occurrence creates an actual expense without double-counting', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'user_id' => $user->id,
        'name' => 'Housing',
        'type' => 'expense',
    ]);

    $create = $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Rent Payment',
        'amount' => '1200.00',
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-12-01',
    ])->assertStatus(201);

    $scheduleId = $create->json('data.id');
    $occurrenceId = $create->json('data.occurrences.0.id');

    // Pay occurrence
    $pay = $this->actingAs($user)->postJson("/api/recurring-expenses/{$scheduleId}/occurrences/{$occurrenceId}/pay", [
        'actual_amount' => '1200.00',
        'paid_date' => '2026-11-01',
    ])->assertStatus(200);

    $pay->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.actual_amount', '1200.00');

    // An actual expense was created
    $this->assertDatabaseHas('expenses', [
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => '1200.00',
        'expense_date' => '2026-11-01 00:00:00',
    ]);

    // Calendar verifies 1 paid occurrence
    $cal = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-11');
    $cal->assertStatus(200)
        ->assertJsonPath('data.summary.paid_total', '1200.00')
        ->assertJsonPath('data.summary.entry_count', 1);

    // Filter by status=paid returns the paid occurrence
    $calPaid = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-11&status=paid');
    $calPaid->assertStatus(200)
        ->assertJsonCount(1, 'data.entries');

    // Filter by status=planned returns 0 entries in November
    $calPlanned = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-11&status=planned');
    $calPlanned->assertStatus(200)
        ->assertJsonCount(0, 'data.entries');
});

test('calendar filters by category and excludes other users schedules', function () {
    $user = User::factory()->create();
    $intruder = User::factory()->create();

    $cat1 = Category::create(['user_id' => $user->id, 'name' => 'Bills', 'type' => 'expense']);
    $cat2 = Category::create(['user_id' => $user->id, 'name' => 'Subscriptions', 'type' => 'expense']);

    $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Electricity',
        'amount' => '80.00',
        'category_id' => $cat1->id,
        'frequency' => 'monthly',
        'start_date' => '2026-11-10',
    ])->assertStatus(201);

    $this->actingAs($user)->postJson('/api/recurring-expenses', [
        'description' => 'Streaming',
        'amount' => '15.00',
        'category_id' => $cat2->id,
        'frequency' => 'monthly',
        'start_date' => '2026-11-20',
    ])->assertStatus(201);

    // User calendar sees both
    $resAll = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-11');
    $resAll->assertStatus(200)->assertJsonCount(2, 'data.entries');

    // Filter by cat1
    $resCat1 = $this->actingAs($user)->getJson("/api/recurring-expenses/calendar?month=2026-11&category_id={$cat1->id}");
    $resCat1->assertStatus(200)->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.schedule.description', 'Electricity');

    // Intruder calendar sees 0
    $resIntruder = $this->actingAs($intruder)->getJson('/api/recurring-expenses/calendar?month=2026-11');
    $resIntruder->assertStatus(200)->assertJsonCount(0, 'data.entries');
});
