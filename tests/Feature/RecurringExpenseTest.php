<?php

use App\Models\BillReminder;
use App\Models\Category;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\RecurringExpenseOccurrence;
use App\Models\User;
use App\Services\RecurringExpenseService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function billPayload(Category $category, array $overrides = []): array
{
    return array_merge([
        'description' => 'Rent',
        'amount' => 1200.00,
        'category_id' => $category->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-01',
        'reminder_days_before' => 3,
        'notes' => 'Paid by transfer',
    ], $overrides);
}

function makeBillSchedule(User $user, Category $category, array $overrides = []): RecurringExpense
{
    $schedule = RecurringExpense::create(array_merge([
        'user_id' => $user->id,
        'description' => 'Rent',
        'amount' => 100.00,
        'category_id' => $category->id,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
        'reminder_days_before' => 3,
        'notes' => null,
    ], $overrides));

    app(RecurringExpenseService::class)->generateInitial($schedule);

    return $schedule->fresh(['category']);
}

function billOccurrence(RecurringExpense $schedule, string $date): RecurringExpenseOccurrence
{
    return $schedule->occurrences()->whereDate('occurrence_date', $date)->firstOrFail();
}

// ----------- Authentication and ownership -----------

test('recurring expense and reminder endpoints require authentication', function () {
    $this->getJson('/api/recurring-expenses')->assertStatus(401);
    $this->getJson('/api/recurring-expenses/calendar')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/preview', [
        'amount' => 10,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
    ])->assertStatus(401);
    $this->postJson('/api/recurring-expenses', [
        'description' => 'Rent',
        'amount' => 10,
        'category_id' => 1,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
    ])->assertStatus(401);
    $this->getJson('/api/recurring-expenses/1')->assertStatus(401);
    $this->putJson('/api/recurring-expenses/1', ['amount' => 10])->assertStatus(401);
    $this->getJson('/api/recurring-expenses/1/occurrences')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/pause')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/resume')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/stop')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/occurrences/1/pay')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/occurrences/1/skip')->assertStatus(401);
    $this->postJson('/api/recurring-expenses/1/occurrences/1/unpaid')->assertStatus(401);
    $this->getJson('/api/bill-reminders')->assertStatus(401);
    $this->postJson('/api/bill-reminders/read-all')->assertStatus(401);
    $this->postJson('/api/bill-reminders/1/read')->assertStatus(401);
});

test('another user cannot view, edit, pause, stop or mark a schedule occurrence', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($owner, $category);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($intruder)->getJson('/api/recurring-expenses')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.schedules');
    $this->actingAs($intruder)->getJson("/api/recurring-expenses/{$schedule->id}")->assertStatus(404);
    $this->actingAs($intruder)->getJson("/api/recurring-expenses/{$schedule->id}/occurrences")->assertStatus(404);
    $this->actingAs($intruder)->putJson("/api/recurring-expenses/{$schedule->id}", ['amount' => 900])->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/pause")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/resume")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/stop")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/skip")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/unpaid")->assertStatus(404);

    $this->actingAs($owner)->getJson('/api/bill-reminders')->assertStatus(200);
    $reminder = BillReminder::query()->firstOrFail();

    $this->actingAs($intruder)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.reminders');
    $this->actingAs($intruder)->postJson("/api/bill-reminders/{$reminder->id}/read")->assertStatus(404);
    $this->actingAs($intruder)->postJson('/api/bill-reminders/read-all')
        ->assertStatus(200)
        ->assertJsonPath('data.updated', 0);

    expect($occurrence->fresh()->status)->toBe('planned')
        ->and($schedule->fresh()->status)->toBe('active');
});

test('malformed, foreign and cross-schedule occurrence identifiers return 404', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $otherSchedule = makeBillSchedule($user, $category, ['description' => 'Other bill']);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->getJson('/api/recurring-expenses/not-a-number')->assertStatus(404);
    $this->actingAs($user)->getJson('/api/recurring-expenses/999999')->assertStatus(404);

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$otherSchedule->id}/occurrences/{$occurrence->id}/pay"
    )->assertStatus(404);

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay"
    )->assertStatus(200);
});

// ----------- Creation, preview and date boundaries -----------

test('owner can create a weekly schedule with inclusive expected occurrences', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $response = $this->actingAs($user)->postJson('/api/recurring-expenses', billPayload($category, [
        'frequency' => 'weekly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-15',
        'amount' => 500,
    ]));

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.description', 'Rent')
        ->assertJsonPath('data.amount', '500.00')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.next_due_date', '2026-11-01')
        ->assertJsonPath('data.expectation_only', true)
        ->assertJsonPath('data.summary.expected_total', '1500.00')
        ->assertJsonCount(3, 'data.occurrences')
        ->assertJsonPath('data.occurrences.0.occurrence_date', '2026-11-01')
        ->assertJsonPath('data.occurrences.1.occurrence_date', '2026-11-08')
        ->assertJsonPath('data.occurrences.2.occurrence_date', '2026-11-15')
        ->assertJsonPath('data.occurrences.0.status', 'planned')
        ->assertJsonPath('data.occurrences.0.display_status', 'upcoming')
        ->assertJsonPath('data.occurrences.0.can_pay', true);

    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseCount('recurring_expenses', 1);
    $this->assertDatabaseCount('recurring_expense_occurrences', 3);
});

test('open-ended schedules generate a bounded rolling horizon and preview it', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $preview = $this->actingAs($user)->postJson('/api/recurring-expenses/preview', [
        'amount' => 100,
        'frequency' => 'monthly',
        'start_date' => '2026-10-20',
    ]);

    $preview->assertStatus(200)
        ->assertJsonPath('data.open_ended', true)
        ->assertJsonPath('data.count', 13)
        ->assertJsonPath('data.start_date', '2026-10-20')
        ->assertJsonPath('data.end_date', '2027-10-20');

    $this->assertDatabaseCount('recurring_expenses', 0);

    $created = $this->actingAs($user)->postJson('/api/recurring-expenses', billPayload($category, [
        'frequency' => 'monthly',
        'start_date' => '2026-10-20',
        'end_date' => null,
    ]));

    $created->assertStatus(201)
        ->assertJsonPath('data.end_date', null)
        ->assertJsonCount(13, 'data.occurrences')
        ->assertJsonPath('data.occurrences.0.occurrence_date', '2026-10-20')
        ->assertJsonPath('data.occurrences.12.occurrence_date', '2027-10-20');
});

test('month-end, leap-day and yearly boundaries use calendar-safe dates', function () {
    $user = User::factory()->create();

    $monthly = $this->actingAs($user)->postJson('/api/recurring-expenses/preview', [
        'amount' => 100,
        'frequency' => 'monthly',
        'start_date' => '2028-01-31',
        'end_date' => '2028-05-31',
    ]);

    $monthly->assertStatus(200)
        ->assertJsonPath('data.count', 5)
        ->assertJsonPath('data.dates.0', '2028-01-31')
        ->assertJsonPath('data.dates.1', '2028-02-29')
        ->assertJsonPath('data.dates.2', '2028-03-31')
        ->assertJsonPath('data.dates.3', '2028-04-30')
        ->assertJsonPath('data.dates.4', '2028-05-31');

    $yearly = $this->actingAs($user)->postJson('/api/recurring-expenses/preview', [
        'amount' => 100,
        'frequency' => 'yearly',
        'start_date' => '2028-02-29',
        'end_date' => '2032-02-29',
    ]);

    $yearly->assertStatus(200)
        ->assertJsonPath('data.count', 5)
        ->assertJsonPath('data.dates.0', '2028-02-29')
        ->assertJsonPath('data.dates.1', '2029-02-28')
        ->assertJsonPath('data.dates.2', '2030-02-28')
        ->assertJsonPath('data.dates.3', '2031-02-28')
        ->assertJsonPath('data.dates.4', '2032-02-29');
});

test('an open-ended schedule with a past start keeps its anchor and does not backfill history', function () {
    Carbon::setTestNow('2026-10-20 09:00:00');

    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $preview = $this->actingAs($user)->postJson('/api/recurring-expenses/preview', [
        'amount' => 100,
        'frequency' => 'monthly',
        'start_date' => '2026-01-31',
    ]);

    $preview->assertStatus(200)
        ->assertJsonPath('data.open_ended', true)
        ->assertJsonPath('data.start_date', '2026-10-31')
        ->assertJsonPath('data.end_date', '2027-10-31')
        ->assertJsonPath('data.count', 13)
        ->assertJsonPath('data.dates.0', '2026-10-31')
        ->assertJsonPath('data.dates.1', '2026-11-30')
        ->assertJsonPath('data.dates.2', '2026-12-31');

    $created = $this->actingAs($user)->postJson('/api/recurring-expenses', billPayload($category, [
        'frequency' => 'monthly',
        'start_date' => '2026-09-30',
        'end_date' => null,
    ]));

    $created->assertStatus(201)
        ->assertJsonPath('data.next_due_date', '2026-10-30')
        ->assertJsonCount(13, 'data.occurrences')
        ->assertJsonPath('data.occurrences.0.occurrence_date', '2026-10-30')
        ->assertJsonPath('data.occurrences.0.display_status', 'upcoming')
        ->assertJsonPath('data.occurrences.12.occurrence_date', '2027-10-30');

    $this->assertDatabaseCount('recurring_expense_occurrences', 13);
});

test('invalid schedule inputs and wrong category types are rejected without writes', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $incomeCategory = makeIncomeCategory();

    foreach ([0, -1, 1.234, 100000000, 'abc'] as $amount) {
        $this->actingAs($user)
            ->postJson('/api/recurring-expenses', billPayload($category, ['amount' => $amount]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    $this->actingAs($user)
        ->postJson('/api/recurring-expenses', billPayload($incomeCategory))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->actingAs($user)
        ->postJson('/api/recurring-expenses', billPayload($category, ['end_date' => '2026-09-30']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);

    $this->actingAs($user)
        ->postJson('/api/recurring-expenses', billPayload($category, ['frequency' => 'fortnightly']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency']);

    $this->actingAs($user)
        ->postJson('/api/recurring-expenses', billPayload($category, ['start_date' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['start_date']);

    $this->actingAs($user)
        ->postJson('/api/recurring-expenses', billPayload($category, ['reminder_days_before' => 31]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reminder_days_before']);

    $this->assertDatabaseCount('recurring_expenses', 0);
    $this->assertDatabaseCount('recurring_expense_occurrences', 0);
});

test('schedule creation ignores client-supplied owner, status and primary key', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();

    $response = $this->actingAs($owner)->postJson('/api/recurring-expenses', billPayload($category, [
        'user_id' => $other->id,
        'status' => 'stopped',
        'id' => 9999,
    ]));

    $response->assertStatus(201)->assertJsonPath('data.status', 'active');
    $scheduleId = $response->json('data.id');

    $this->actingAs($owner)->putJson("/api/recurring-expenses/{$scheduleId}", [
        'amount' => 200,
        'status' => 'stopped',
        'user_id' => $other->id,
    ])->assertStatus(200)->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('recurring_expenses', [
        'id' => $scheduleId,
        'user_id' => $owner->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseMissing('recurring_expenses', ['id' => 9999]);
});

test('repeating schedule creation with the same idempotency key creates one schedule', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $payload = billPayload($category);
    $key = 'recurring-expense-create-001';

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/recurring-expenses', $payload)
        ->assertStatus(201)
        ->assertHeaderMissing('Idempotent-Replay');

    $second = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/recurring-expenses', $payload)
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->assertDatabaseCount('recurring_expenses', 1);
    $this->assertDatabaseCount('recurring_expense_occurrences', 3);
});

// ----------- Editing and lifecycle -----------

test('editing a schedule preserves paid and skipped history and only rebuilds future expectations', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);

    $paidOccurrence = billOccurrence($schedule, '2026-10-08');
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$paidOccurrence->id}/pay"
    )->assertStatus(200);

    $skippedOccurrence = billOccurrence($schedule, '2026-10-15');
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$skippedOccurrence->id}/skip"
    )->assertStatus(200);

    $future = billOccurrence($schedule, '2026-10-22');

    $updated = $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", [
        'amount' => 200,
        'end_date' => '2026-11-12',
    ]);

    $updated->assertStatus(200)
        ->assertJsonPath('data.amount', '200.00')
        ->assertJsonPath('data.occurrences.1.status', 'paid')
        ->assertJsonPath('data.occurrences.1.expected_amount', '100.00')
        ->assertJsonPath('data.occurrences.2.status', 'skipped')
        ->assertJsonPath('data.occurrences.2.expected_amount', '100.00');

    $this->assertDatabaseMissing('recurring_expense_occurrences', ['id' => $future->id]);
    $this->assertDatabaseHas('recurring_expense_occurrences', [
        'occurrence_date' => '2026-10-22',
        'expected_amount' => 200,
        'status' => 'planned',
    ]);
    $this->assertDatabaseHas('recurring_expense_occurrences', [
        'occurrence_date' => '2026-11-12',
        'expected_amount' => 200,
        'status' => 'planned',
    ]);
    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseHas('expenses', ['amount' => 100, 'expense_date' => '2026-10-08 00:00:00']);

    $futureAfterEdit = billOccurrence($schedule->fresh(), '2026-10-22');
    $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", [
        'notes' => 'Updated note only',
    ])->assertStatus(200);

    expect(billOccurrence($schedule->fresh(), '2026-10-22')->id)->toBe($futureAfterEdit->id);
});

test('pausing, resuming and stopping preserve actual expenses and cancel future expectations', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $paidOccurrence = billOccurrence($schedule, '2026-10-08');
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$paidOccurrence->id}/pay"
    )->assertStatus(200);

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/pause")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'paused');
    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/resume")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active');

    $stopped = $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/stop");
    $stopped->assertStatus(200)
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.next_due_date', null);

    $this->assertDatabaseHas('recurring_expense_occurrences', [
        'id' => $paidOccurrence->id,
        'status' => 'paid',
    ]);
    $this->assertDatabaseHas('recurring_expense_occurrences', [
        'occurrence_date' => '2026-10-22',
        'status' => 'cancelled',
    ]);
    $this->assertDatabaseCount('expenses', 1);

    $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", ['amount' => 10])->assertStatus(409);
    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/resume")->assertStatus(409);
    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/pause")->assertStatus(409);

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/stop")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'stopped');
});

// ----------- Expected versus actual payments -----------

test('marking a bill paid creates exactly one actual expense and replay is safe', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $occurrence = billOccurrence($schedule, '2026-10-22');
    $url = "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay";
    $key = 'bill-payment-001';

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson($url, ['actual_amount' => 125, 'paid_date' => '2026-10-21'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.display_status', 'paid')
        ->assertJsonPath('data.expected_amount', '100.00')
        ->assertJsonPath('data.actual_amount', '125.00')
        ->assertJsonPath('data.payment_date', '2026-10-21')
        ->assertHeaderMissing('Idempotent-Replay');

    $expenseId = $first->json('data.expense_id');
    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseHas('expenses', [
        'id' => $expenseId,
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 125,
        'expense_date' => '2026-10-21 00:00:00',
        'description' => 'Rent',
    ]);

    $second = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson($url, ['actual_amount' => 125, 'paid_date' => '2026-10-21'])
        ->assertStatus(200)
        ->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data.expense_id'))->toBe($expenseId);
    $this->assertDatabaseCount('expenses', 1);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'bill-payment-different-key')
        ->postJson($url, ['actual_amount' => 125])
        ->assertStatus(409);

    $this->assertDatabaseCount('expenses', 1);
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/skip"
    )->assertStatus(409);
});

test('skipped and unpaid occurrences never create expenses and overdue is derived', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);

    $overdue = billOccurrence($schedule, '2026-10-15');
    $future = billOccurrence($schedule, '2026-10-22');

    $this->actingAs($user)->getJson("/api/recurring-expenses/{$schedule->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.occurrences.2.display_status', 'overdue')
        ->assertJsonPath('data.occurrences.3.display_status', 'upcoming')
        ->assertJsonPath('data.summary.overdue_total', '300.00');

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$overdue->id}/skip"
    )->assertStatus(200)
        ->assertJsonPath('data.status', 'skipped')
        ->assertJsonPath('data.display_status', 'skipped');

    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseMissing('bill_reminders', ['recurring_expense_occurrence_id' => $overdue->id]);

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$overdue->id}/unpaid"
    )->assertStatus(200)
        ->assertJsonPath('data.status', 'planned')
        ->assertJsonPath('data.display_status', 'overdue');

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$future->id}/skip"
    )->assertStatus(200);
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$future->id}/pay"
    )->assertStatus(409);

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$future->id}/unpaid"
    )->assertStatus(200);
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$future->id}/pay"
    )->assertStatus(200);

    $this->assertDatabaseCount('expenses', 1);
    $this->assertDatabaseCount('recurring_expense_occurrences', 5);
});

test('payment validation rejects invalid amounts and dates without duplicate expenses', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $occurrence = billOccurrence($schedule, '2026-10-22');
    $url = "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay";

    $this->actingAs($user)->postJson($url, ['actual_amount' => 0])
        ->assertStatus(422)->assertJsonValidationErrors(['actual_amount']);
    $this->actingAs($user)->postJson($url, ['actual_amount' => 1.234])
        ->assertStatus(422)->assertJsonValidationErrors(['actual_amount']);
    $this->actingAs($user)->postJson($url, ['paid_date' => 'not-a-date'])
        ->assertStatus(422)->assertJsonValidationErrors(['paid_date']);

    $this->assertDatabaseCount('expenses', 0);
    expect($occurrence->fresh()->status)->toBe('planned');
});

// ----------- Bill calendar -----------

test('bill calendar returns one month of owner data with totals and filters', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $rentCategory = makeExpenseCategory();
    $utilityCategory = makeExpenseCategory();

    $rent = makeBillSchedule($user, $rentCategory, [
        'description' => 'Rent',
        'amount' => 100,
        'frequency' => 'monthly',
        'start_date' => '2026-09-05',
        'end_date' => '2026-12-05',
    ]);
    $utility = makeBillSchedule($user, $utilityCategory, [
        'description' => 'Electricity',
        'amount' => 50,
        'frequency' => 'monthly',
        'start_date' => '2026-09-15',
        'end_date' => '2026-12-15',
    ]);
    makeBillSchedule($other, $rentCategory, [
        'description' => 'Other user rent',
        'amount' => 999,
        'frequency' => 'monthly',
        'start_date' => '2026-09-20',
        'end_date' => '2026-12-20',
    ]);

    $paid = billOccurrence($rent, '2026-10-05');
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$rent->id}/occurrences/{$paid->id}/pay"
    )->assertStatus(200);

    $october = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-10');
    $october->assertStatus(200)
        ->assertJsonPath('data.month', '2026-10')
        ->assertJsonPath('data.month_label', 'October 2026')
        ->assertJsonPath('data.days_in_month', 31)
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.summary.expected_total', '150.00')
        ->assertJsonPath('data.summary.paid_total', '100.00')
        ->assertJsonPath('data.summary.unpaid_total', '50.00')
        ->assertJsonPath('data.summary.overdue_total', '50.00')
        ->assertJsonPath('data.summary.entry_count', 2)
        ->assertJsonCount(3, 'data.overdue')
        ->assertJsonCount(2, 'data.upcoming');

    $november = $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-11');
    $november->assertStatus(200)
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.entries.0.occurrence_date', '2026-11-05')
        ->assertJsonPath('data.entries.1.occurrence_date', '2026-11-15');

    $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-10&status=overdue')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.schedule.description', 'Electricity');

    $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-10&status=paid')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.schedule.description', 'Rent');

    $this->actingAs($user)->getJson("/api/recurring-expenses/calendar?month=2026-10&category_id={$utilityCategory->id}")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.schedule.description', 'Electricity');

    $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-13')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['month']);
});

test('paused schedules stay visible on the calendar but do not appear in upcoming reminders', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category, [
        'frequency' => 'monthly',
        'start_date' => '2026-10-25',
        'end_date' => '2026-12-25',
    ]);

    $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-10')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.upcoming');

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/pause")->assertStatus(200);

    $this->actingAs($user)->getJson('/api/recurring-expenses/calendar?month=2026-10')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.schedule.status', 'paused')
        ->assertJsonCount(0, 'data.upcoming')
        ->assertJsonCount(0, 'data.overdue');
});

// ----------- Reminders -----------

test('reminders are deduplicated, transition with the due date, and are owner scoped', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();

    $schedule = makeBillSchedule($user, $category, [
        'frequency' => 'weekly',
        'start_date' => '2026-10-18',
        'end_date' => '2026-11-15',
    ]);
    makeBillSchedule($other, $category, [
        'description' => 'Other reminder',
        'frequency' => 'weekly',
        'start_date' => '2026-10-18',
        'end_date' => '2026-11-15',
    ]);

    $first = $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders')
        ->assertJsonPath('data.reminders.0.type', 'overdue')
        ->assertJsonPath('data.reminders.0.days_until_due', -2)
        ->assertJsonPath('data.summary.overdue_count', 1);

    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders');

    Carbon::setTestNow('2026-10-22 12:00:00');
    $upcoming = $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.reminders')
        ->assertJsonPath('data.reminders.1.type', 'upcoming')
        ->assertJsonPath('data.reminders.1.window_days', 3);

    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.reminders');

    Carbon::setTestNow('2026-10-25 12:00:00');
    $due = $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.reminders')
        ->assertJsonPath('data.reminders.0.type', 'overdue')
        ->assertJsonPath('data.reminders.1.type', 'due')
        ->assertJsonPath('data.reminders.1.days_until_due', 0);

    $dueReminderId = $due->json('data.reminders.1.id');
    $this->actingAs($user)->postJson("/api/bill-reminders/{$dueReminderId}/read")
        ->assertStatus(200)
        ->assertJsonPath('data.is_read', true);

    $this->actingAs($user)->postJson('/api/bill-reminders/read-all')
        ->assertStatus(200)
        ->assertJsonPath('data.updated', 1);

    $dueOccurrence = billOccurrence($schedule, '2026-10-25');
    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$dueOccurrence->id}/pay"
    )->assertStatus(200);

    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders')
        ->assertJsonPath('data.reminders.0.type', 'overdue');

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/pause")
        ->assertStatus(200);
    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.reminders');

    $this->actingAs($user)->postJson("/api/recurring-expenses/{$schedule->id}/resume")
        ->assertStatus(200);
    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders')
        ->assertJsonPath('data.reminders.0.type', 'overdue');
});

test('changing the reminder window or due dates reconciles old reminders', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category, [
        'frequency' => 'monthly',
        'start_date' => '2026-10-25',
        'end_date' => '2026-12-25',
        'reminder_days_before' => 3,
    ]);

    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.reminders');

    $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", [
        'reminder_days_before' => 7,
    ])->assertStatus(200);

    $windowSeven = $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders')
        ->assertJsonPath('data.reminders.0.window_days', 7);
    $reminderId = $windowSeven->json('data.reminders.0.id');

    $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", [
        'reminder_days_before' => 5,
    ])->assertStatus(200);

    $windowFive = $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.reminders')
        ->assertJsonPath('data.reminders.0.window_days', 5);
    expect($windowFive->json('data.reminders.0.id'))->not->toBe($reminderId);

    $this->actingAs($user)->putJson("/api/recurring-expenses/{$schedule->id}", [
        'frequency' => 'monthly',
        'start_date' => '2026-11-15',
        'end_date' => '2026-12-15',
    ])->assertStatus(200);

    $this->actingAs($user)->getJson('/api/bill-reminders')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.reminders');

    expect(BillReminder::query()->count())->toBe(0);
});

test('the reminder scheduler command is safe to rerun and skips paused schedules', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeBillSchedule($user, $category, [
        'frequency' => 'weekly',
        'start_date' => '2026-10-18',
        'end_date' => '2026-11-15',
    ]);

    $paused = makeBillSchedule($user, $category, [
        'description' => 'Paused bill',
        'frequency' => 'monthly',
        'start_date' => '2026-10-25',
        'end_date' => '2026-12-25',
    ]);
    $this->actingAs($user)->postJson("/api/recurring-expenses/{$paused->id}/pause")->assertStatus(200);

    $this->artisan('recurring-expenses:sync-reminders')->assertSuccessful();
    $firstCount = BillReminder::query()->count();
    expect($firstCount)->toBe(1);

    $this->artisan('recurring-expenses:sync-reminders')->assertSuccessful();
    expect(BillReminder::query()->count())->toBe($firstCount)
        ->and(BillReminder::query()->where('type', 'overdue')->count())->toBe(1);
});

// ----------- Soft deletes and integrity -----------

test('a linked actual expense can be soft-deleted and restored without duplicating the payment', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $occurrence = billOccurrence($schedule, '2026-10-22');

    $paid = $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay"
    )->assertStatus(200);
    $expenseId = $paid->json('data.expense_id');

    $this->actingAs($user)->deleteJson("/api/expenses/{$expenseId}")->assertStatus(204);
    $this->assertSoftDeleted('expenses', ['id' => $expenseId]);

    $this->actingAs($user)->getJson("/api/recurring-expenses/{$schedule->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.occurrences.3.status', 'paid')
        ->assertJsonPath('data.occurrences.3.expense_deleted', true)
        ->assertJsonPath('data.occurrences.3.actual_amount', '100.00')
        ->assertJsonPath('data.summary.paid_total', '100.00');

    $this->actingAs($user)->postJson(
        "/api/recurring-expenses/{$schedule->id}/occurrences/{$occurrence->id}/pay"
    )->assertStatus(409);

    $this->assertDatabaseCount('expenses', 1);

    $this->actingAs($user)->postJson("/api/expenses/{$expenseId}/restore")->assertStatus(200);
    $this->assertNotSoftDeleted('expenses', ['id' => $expenseId]);

    $this->actingAs($user)->getJson("/api/recurring-expenses/{$schedule->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.occurrences.3.expense_deleted', false)
        ->assertJsonPath('data.occurrences.3.status', 'paid');
});

test('categories referenced by a schedule cannot change type or be deleted', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    makeBillSchedule($user, $category);

    $this->actingAs($user)->putJson("/api/categories/{$category->id}", ['type' => 'income'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change category type: category has associated recurring expense schedules.');

    $this->actingAs($user)->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete category: category has associated recurring expense schedules.');

    $this->assertDatabaseHas('categories', ['id' => $category->id, 'type' => 'expense']);
    $this->assertDatabaseCount('recurring_expenses', 1);
});

test('occurrence identity is unique per schedule and date', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $schedule = makeBillSchedule($user, $category);
    $existing = $schedule->occurrences()->firstOrFail();

    expect(function () use ($schedule, $existing) {
        DB::table('recurring_expense_occurrences')->insert([
            'recurring_expense_id' => $schedule->id,
            'occurrence_date' => $existing->occurrence_date->toDateString(),
            'expected_amount' => 100,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});
