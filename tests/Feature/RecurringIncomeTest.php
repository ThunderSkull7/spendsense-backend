<?php

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Income;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Services\RecurringIncomeService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function recurringPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tutoring',
        'amount' => 500.00,
        'frequency' => 'weekly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-15',
        'notes' => 'Weekend sessions',
    ], $overrides);
}

function makeRecurringSchedule(User $user, array $overrides = []): RecurringIncome
{
    $schedule = RecurringIncome::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Tutoring',
        'amount' => 500.00,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
        'notes' => null,
    ], $overrides));

    app(RecurringIncomeService::class)->generateInitial($schedule);

    return $schedule->fresh();
}

// ----------- Authentication and ownership -----------

test('recurring income endpoints require authentication', function () {
    $this->getJson('/api/recurring-incomes')->assertStatus(401);
    $this->postJson('/api/recurring-incomes', recurringPayload())->assertStatus(401);
    $this->postJson('/api/recurring-incomes/preview', recurringPayload())->assertStatus(401);
    $this->getJson('/api/recurring-incomes/1')->assertStatus(401);
    $this->getJson('/api/recurring-incomes/1/occurrences')->assertStatus(401);
    $this->putJson('/api/recurring-incomes/1', ['amount' => 10])->assertStatus(401);
    $this->postJson('/api/recurring-incomes/1/pause')->assertStatus(401);
    $this->postJson('/api/recurring-incomes/1/resume')->assertStatus(401);
    $this->postJson('/api/recurring-incomes/1/stop')->assertStatus(401);
    $this->postJson('/api/recurring-incomes/1/occurrences/1/receive')->assertStatus(401);
    $this->putJson('/api/recurring-incomes/1/occurrences/1/receipt', [
        'actual_amount' => 10,
        'actual_received_date' => '2026-10-01',
    ])->assertStatus(401);
});

test('index only returns the authenticated owner schedules', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    makeRecurringSchedule($owner, ['name' => 'Owner schedule']);
    makeRecurringSchedule($other, ['name' => 'Other schedule']);

    $response = $this->actingAs($owner)->getJson('/api/recurring-incomes');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.schedules')
        ->assertJsonPath('data.schedules.0.name', 'Owner schedule');
});

test('another user cannot read, edit, pause, stop or receive a schedule', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $schedule = makeRecurringSchedule($owner);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($intruder)->getJson("/api/recurring-incomes/{$schedule->id}")->assertStatus(404);
    $this->actingAs($intruder)->getJson("/api/recurring-incomes/{$schedule->id}/occurrences")->assertStatus(404);
    $this->actingAs($intruder)->putJson("/api/recurring-incomes/{$schedule->id}", ['amount' => 900])->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-incomes/{$schedule->id}/pause")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-incomes/{$schedule->id}/stop")->assertStatus(404);
    $this->actingAs($intruder)->postJson("/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive")->assertStatus(404);

    $this->assertDatabaseHas('recurring_incomes', ['id' => $schedule->id, 'status' => 'active']);
});

test('malformed, foreign and cross-schedule identifiers return 404', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $schedule = makeRecurringSchedule($owner);
    $otherSchedule = makeRecurringSchedule($owner, ['name' => 'Second schedule']);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($owner)->getJson('/api/recurring-incomes/not-a-number')->assertStatus(404);
    $this->actingAs($owner)->getJson('/api/recurring-incomes/999999')->assertStatus(404);
    $this->actingAs($owner)->getJson("/api/recurring-incomes/{$schedule->id}/occurrences/not-a-number")->assertStatus(404);

    // An occurrence id that belongs to another schedule is not found through
    // this schedule's scoped relationship.
    $this->actingAs($owner)->postJson(
        "/api/recurring-incomes/{$otherSchedule->id}/occurrences/{$occurrence->id}/receive"
    )->assertStatus(404);

    $this->actingAs($owner)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive"
    )->assertStatus(200);

    $this->actingAs($intruder)->putJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receipt",
        ['actual_amount' => 1, 'actual_received_date' => '2026-10-02']
    )->assertStatus(404);
});

test('schedule creation and update ignore client-supplied owner, status and primary key', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $response = $this->actingAs($owner)->postJson('/api/recurring-incomes', recurringPayload([
        'user_id' => $other->id,
        'status' => 'stopped',
        'id' => 9999,
    ]));

    $response->assertStatus(201)->assertJsonPath('data.status', 'active');
    $scheduleId = $response->json('data.id');

    $this->actingAs($owner)->putJson("/api/recurring-incomes/{$scheduleId}", [
        'amount' => 650,
        'status' => 'stopped',
        'user_id' => $other->id,
    ])->assertStatus(200)->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('recurring_incomes', [
        'id' => $scheduleId,
        'user_id' => $owner->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseMissing('recurring_incomes', ['id' => 9999]);
});

test('receipt attempts reject invalid actual amounts and dates', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();
    $url = "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive";

    $this->actingAs($user)->postJson($url, ['actual_amount' => 0])
        ->assertStatus(422)->assertJsonValidationErrors(['actual_amount']);
    $this->actingAs($user)->postJson($url, ['actual_amount' => 1.234])
        ->assertStatus(422)->assertJsonValidationErrors(['actual_amount']);
    $this->actingAs($user)->postJson($url, ['actual_received_date' => 'not-a-date'])
        ->assertStatus(422)->assertJsonValidationErrors(['actual_received_date']);

    $this->assertDatabaseCount('incomes', 0);
});

// ----------- Creation and preview -----------

test('owner can create a weekly schedule and occurrences are generated inclusively', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload());

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Tutoring')
        ->assertJsonPath('data.amount', '500.00')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.next_due_date', '2026-11-01')
        ->assertJsonPath('data.summary.expected_total', '1500.00')
        ->assertJsonCount(3, 'data.occurrences')
        ->assertJsonPath('data.occurrences.0.occurrence_date', '2026-11-01')
        ->assertJsonPath('data.occurrences.1.occurrence_date', '2026-11-08')
        ->assertJsonPath('data.occurrences.2.occurrence_date', '2026-11-15')
        ->assertJsonPath('data.occurrences.0.status', 'planned')
        ->assertJsonPath('data.occurrences.0.display_status', 'planned');

    $this->assertDatabaseCount('recurring_income_occurrences', 3);
});

test('preview returns calculated dates and total without writing records', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/recurring-incomes/preview', [
        'amount' => 500,
        'frequency' => 'weekly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-15',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.count', 3)
        ->assertJsonPath('data.total_expected', '1500.00')
        ->assertJsonCount(3, 'data.dates');

    $this->assertDatabaseCount('recurring_incomes', 0);
    $this->assertDatabaseCount('recurring_income_occurrences', 0);
});

test('invalid schedule amounts are rejected', function ($amount) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload(['amount' => $amount]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
})->with([0, -1, 1.234, 100000000, 'abc']);

test('invalid dates and frequencies are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload(['end_date' => '2026-10-31']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);

    $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload(['frequency' => 'fortnightly']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency']);

    $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload(['start_date' => '11/01/2026']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['start_date']);
});

test('a schedule above the occurrence limit is rejected rather than truncated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/recurring-incomes', recurringPayload([
            'frequency' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-02',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);

    $this->assertDatabaseCount('recurring_incomes', 0);
});

// ----------- Idempotency and database uniqueness -----------

test('generation is idempotent', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);

    $before = $schedule->occurrences()->count();
    app(RecurringIncomeService::class)->generateInitial($schedule);
    app(RecurringIncomeService::class)->generateInitial($schedule->fresh());

    expect($schedule->occurrences()->count())->toBe($before)
        ->and($before)->toBe(5);
});

test('the database rejects a duplicate occurrence for the same schedule and date', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $existing = $schedule->occurrences()->firstOrFail();

    $this->expectException(QueryException::class);

    // Use a raw insert so SQLite stores the same date-only text as the
    // occurrence generator. PostgreSQL DATE columns normalize both forms.
    DB::table('recurring_income_occurrences')->insert([
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => $existing->occurrence_date->toDateString(),
        'expected_amount' => 500,
        'status' => 'planned',
        'income_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// ----------- Receipt behaviour -----------

test('receiving an occurrence creates exactly one linked actual income', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $response = $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive",
        ['actual_amount' => 480, 'actual_received_date' => '2026-10-03']
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.actual_amount', '480.00')
        ->assertJsonPath('data.actual_received_date', '2026-10-03');

    $this->assertDatabaseCount('incomes', 1);
    $this->assertDatabaseHas('incomes', [
        'user_id' => $user->id,
        'amount' => 480,
        'source' => 'Tutoring',
        'frequency' => 'one-time',
    ]);
    $income = Income::where('user_id', $user->id)->firstOrFail();
    expect($income->income_date->toDateString())->toBe('2026-10-03');
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $occurrence->id,
        'status' => 'received',
    ]);
    expect($occurrence->fresh()->income_id)->not->toBeNull();
});

test('receiving the same occurrence twice returns conflict and creates no second income', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();
    $url = "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive";

    $this->actingAs($user)->postJson($url)->assertStatus(200);
    $this->actingAs($user)->postJson($url)->assertStatus(409);

    $this->assertDatabaseCount('incomes', 1);
});

test('receipt and occurrence update roll back together when income creation fails', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    Income::creating(function () {
        throw new RuntimeException('forced income failure');
    });

    $this->withoutExceptionHandling();

    expect(function () use ($user, $schedule, $occurrence) {
        $this->actingAs($user)->postJson(
            "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive"
        );
    })->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('incomes', 0);
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $occurrence->id,
        'status' => 'planned',
        'income_id' => null,
    ]);
});

test('receipt rolls back the created income if the occurrence update fails', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    RecurringIncomeOccurrence::updating(function () {
        throw new RuntimeException('forced occurrence update failure');
    });

    $this->withoutExceptionHandling();

    expect(function () use ($user, $schedule, $occurrence) {
        $this->actingAs($user)->postJson(
            "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive"
        );
    })->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('incomes', 0);
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $occurrence->id,
        'status' => 'planned',
        'income_id' => null,
    ]);
});

test('correcting a receipt updates the linked income and keeps the expected amount', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive",
        ['actual_amount' => 480, 'actual_received_date' => '2026-10-03']
    )->assertStatus(200);

    $response = $this->actingAs($user)->putJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receipt",
        ['actual_amount' => 475.50, 'actual_received_date' => '2026-10-05']
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.expected_amount', '500.00')
        ->assertJsonPath('data.actual_amount', '475.50')
        ->assertJsonPath('data.actual_received_date', '2026-10-05');

    $income = Income::where('user_id', $user->id)->firstOrFail();
    expect($income->amount)->toBe('475.50')
        ->and($income->income_date->toDateString())->toBe('2026-10-05');
});

test('a planned occurrence cannot be corrected as a receipt', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->putJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receipt",
        ['actual_amount' => 400, 'actual_received_date' => '2026-10-03']
    )->assertStatus(409);
});

test('receipt correction refuses an occurrence linked to another user\'s income', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $schedule = makeRecurringSchedule($owner);
    $occurrence = $schedule->occurrences()->firstOrFail();
    $foreignIncome = Income::factory()->create([
        'user_id' => $intruder->id,
        'amount' => 100,
        'income_date' => '2026-10-01',
    ]);

    // Simulate a corrupted/hostile link that the API itself would never
    // create: the occurrence points at another user's income row.
    DB::table('recurring_income_occurrences')->where('id', $occurrence->id)->update([
        'status' => 'received',
        'income_id' => $foreignIncome->id,
    ]);

    $this->actingAs($owner)->putJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receipt",
        ['actual_amount' => 1, 'actual_received_date' => '2026-10-02']
    )->assertStatus(409);

    // Nothing about the other user's record is exposed or modified.
    expect($foreignIncome->fresh()->amount)->toBe('100.00')
        ->and($foreignIncome->fresh()->income_date->toDateString())->toBe('2026-10-01');
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $occurrence->id,
        'status' => 'received',
    ]);
});

test('a linked income cannot be deleted through the existing income endpoint', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive"
    )->assertStatus(200);

    $income = Income::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->deleteJson("/api/incomes/{$income->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('incomes', ['id' => $income->id]);
});

test('editing a linked income is reflected once through the occurrence relationship', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive",
        ['actual_amount' => 480, 'actual_received_date' => '2026-10-03']
    )->assertStatus(200);

    $income = Income::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->putJson("/api/incomes/{$income->id}", [
        'amount' => 510,
        'income_date' => '2026-10-04',
    ])->assertStatus(200);

    $detail = $this->actingAs($user)->getJson("/api/recurring-incomes/{$schedule->id}");
    $detail->assertStatus(200)
        ->assertJsonPath('data.occurrences.0.actual_amount', '510.00')
        ->assertJsonPath('data.occurrences.0.actual_received_date', '2026-10-04');

    $this->assertDatabaseCount('incomes', 1);
});

// ----------- Reporting isolation -----------

test('planned occurrences never affect actual income analytics', function () {
    $user = User::factory()->create();
    makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
    ]);

    $before = $this->actingAs($user)->getJson('/api/analytics/cash-flow?year=2026&month=10');
    $before->assertStatus(200)->assertJsonPath('data.total_income', 0);

    $schedule = $user->recurringIncomes()->firstOrFail();
    $occurrence = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$occurrence->id}/receive",
        ['actual_amount' => 480, 'actual_received_date' => '2026-10-03']
    )->assertStatus(200);

    $after = $this->actingAs($user)->getJson('/api/analytics/cash-flow?year=2026&month=10');
    $after->assertStatus(200)
        ->assertJsonPath('data.total_income', 480)
        ->assertJsonPath('data.total_expenses', 0);
});

// ----------- Edit, pause, resume and stop -----------

test('editing a schedule preserves received receipts and rebuilds only future planned dates', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-30',
    ]);
    $received = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$received->id}/receive"
    )->assertStatus(200);

    $response = $this->actingAs($user)->putJson("/api/recurring-incomes/{$schedule->id}", [
        'amount' => 600,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-12-31',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.frequency', 'monthly')
        ->assertJsonPath('data.amount', '600.00');

    $schedule = $schedule->fresh();
    $received->refresh();

    expect($received->status)->toBe('received')
        ->and($received->expected_amount)->toBe('500.00')
        ->and($received->income->amount)->toBe('500.00');

    $planned = $schedule->occurrences()
        ->where('status', 'planned')
        ->orderBy('occurrence_date')
        ->pluck('occurrence_date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString())
        ->all();

    // Past planned rows are history; future rows follow the new monthly cadence.
    expect($planned)->toBe(['2026-10-08', '2026-10-15', '2026-11-01', '2026-12-01']);

    $future = $schedule->occurrences()
        ->where('status', 'planned')
        ->whereDate('occurrence_date', '>=', '2026-10-19')
        ->get();
    expect($future)->toHaveCount(2);
    $future->each(fn ($occurrence) => expect($occurrence->expected_amount)->toBe('600.00'));

    expect($schedule->occurrences()->whereDate('occurrence_date', '2026-10-01')->count())->toBe(1);
});

test('a stopped schedule cannot be edited', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/stop")->assertStatus(200);

    $this->actingAs($user)->putJson("/api/recurring-incomes/{$schedule->id}", ['amount' => 900])
        ->assertStatus(409);
});

test('pause and resume change status without deleting occurrences', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);
    $count = $schedule->occurrences()->count();
    $futurePlanned = $schedule->occurrences()
        ->where('status', 'planned')
        ->whereDate('occurrence_date', '>=', '2026-10-19')
        ->count();

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/pause")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'paused');

    // Paused schedules keep their planned dates so resuming does not have to
    // reconstruct history; the API and UI report the pause explicitly.
    expect($schedule->occurrences()->count())->toBe($count)
        ->and($schedule->occurrences()
            ->where('status', 'planned')
            ->whereDate('occurrence_date', '>=', '2026-10-19')
            ->count())->toBe($futurePlanned);

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/resume")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active');

    expect($schedule->occurrences()->count())->toBe($count);
});

test('editing a schedule to end in the past keeps history without future planned dates', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-30',
    ]);
    $received = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$received->id}/receive",
        ['actual_amount' => 480, 'actual_received_date' => '2026-10-03']
    )->assertStatus(200);

    $response = $this->actingAs($user)->putJson("/api/recurring-incomes/{$schedule->id}", [
        'end_date' => '2026-10-10',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $received->id,
        'status' => 'received',
    ]);
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => '2026-10-08',
        'status' => 'planned',
    ]);
    expect($schedule->occurrences()
        ->where('status', 'planned')
        ->whereDate('occurrence_date', '>=', '2026-10-19')
        ->count())->toBe(0);
});

test('editing a schedule cannot put the end date before the start date', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user);

    $this->actingAs($user)->putJson("/api/recurring-incomes/{$schedule->id}", [
        'end_date' => '2026-09-30',
    ])->assertStatus(422)->assertJsonValidationErrors(['end_date']);
});

test('a stopped schedule rejects pause, resume and further receipts', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-30',
    ]);
    $future = $schedule->occurrences()
        ->where('status', 'planned')
        ->whereDate('occurrence_date', '>=', '2026-10-19')
        ->firstOrFail();

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/stop")->assertStatus(200);

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/pause")->assertStatus(409);
    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/resume")->assertStatus(409);
    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$future->id}/receive"
    )->assertStatus(409);
});

test('stopping cancels future planned dates but keeps miss history and receipts', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-30',
    ]);
    $received = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$received->id}/receive"
    )->assertStatus(200);

    $response = $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/stop");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'stopped')
        ->assertJsonPath('data.next_due_date', null);

    $this->assertDatabaseHas('recurring_income_occurrences', [
        'id' => $received->id,
        'status' => 'received',
    ]);
    $this->assertDatabaseHas('recurring_income_occurrences', [
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => '2026-10-08',
        'status' => 'planned',
    ]);
    $this->assertDatabaseMissing('recurring_income_occurrences', [
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => '2026-10-22',
        'status' => 'planned',
    ]);
    expect($schedule->occurrences()->where('status', 'cancelled')->count())->toBeGreaterThan(0);

    $this->actingAs($user)->postJson("/api/recurring-incomes/{$schedule->id}/resume")
        ->assertStatus(409);
});

test('overdue planned dates are reported without changing stored status', function () {
    $user = User::factory()->create();
    makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
    ]);

    $response = $this->actingAs($user)->getJson('/api/recurring-incomes');

    $response->assertStatus(200)
        ->assertJsonPath('data.schedules.0.summary.overdue_count', 3)
        ->assertJsonPath('data.schedules.0.summary.overdue_total', '1500.00')
        ->assertJsonPath('data.schedules.0.summary.upcoming_count', 2)
        ->assertJsonPath('data.schedules.0.summary.upcoming_total', '1000.00')
        ->assertJsonPath('data.schedules.0.next_due_date', '2026-10-22');

    $occurrences = $response->json('data.schedules.0.occurrences');
    // The list payload intentionally omits occurrence rows.
    expect($occurrences)->toBeNull();

    $detail = $this->actingAs($user)->getJson('/api/recurring-incomes/'.$response->json('data.schedules.0.id'));
    $detail->assertStatus(200)
        ->assertJsonPath('data.occurrences.0.display_status', 'overdue')
        ->assertJsonPath('data.occurrences.0.status', 'planned')
        ->assertJsonPath('data.occurrences.3.display_status', 'planned');
});

test('summary aggregate separates expected, received and overdue money', function () {
    $user = User::factory()->create();
    $schedule = makeRecurringSchedule($user, [
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
    ]);
    $first = $schedule->occurrences()->firstOrFail();

    $this->actingAs($user)->postJson(
        "/api/recurring-incomes/{$schedule->id}/occurrences/{$first->id}/receive",
        ['actual_amount' => 480]
    )->assertStatus(200);

    $response = $this->actingAs($user)->getJson('/api/recurring-incomes');

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.expected_total', '2500.00')
        ->assertJsonPath('data.summary.received_total', '480.00')
        ->assertJsonPath('data.summary.overdue_count', 2)
        ->assertJsonPath('data.summary.overdue_total', '1000.00')
        ->assertJsonPath('data.summary.upcoming_total', '1000.00');
});

// ----------- Existing financial write integrity -----------

test('existing income and expense routes reject zero and negative amounts', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $income = Income::factory()->create(['user_id' => $user->id, 'amount' => 100]);
    $expense = Expense::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 100,
    ]);

    foreach ([0, -1] as $amount) {
        $this->actingAs($user)->postJson('/api/incomes', [
            'amount' => $amount,
            'source' => 'Invalid',
            'income_date' => '2026-10-19',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($user)->putJson("/api/incomes/{$income->id}", ['amount' => $amount])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => $amount,
            'category_id' => $category->id,
            'expense_date' => '2026-10-19',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($user)->putJson("/api/expenses/{$expense->id}", ['amount' => $amount])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }
});

test('existing financial routes reject excessive amounts and excess decimal places', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    foreach ([100000000, 10.999, 'abc', 0.001] as $amount) {
        $this->actingAs($user)->postJson('/api/incomes', [
            'amount' => $amount,
            'source' => 'Invalid',
            'income_date' => '2026-10-19',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => $amount,
            'category_id' => $category->id,
            'expense_date' => '2026-10-19',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }
});

test('postgres enforces positive amounts for new writes but preserves legacy rows', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL CHECK constraints are PostgreSQL-specific.');
    }

    $user = User::factory()->create();
    $category = makeExpenseCategory();

    // Simulate deployment onto a database with an invalid legacy row: the
    // legacy row exists before the constraint is added, then the constraint is
    // added as NOT VALID so the migration can complete.
    DB::statement('ALTER TABLE incomes DROP CONSTRAINT IF EXISTS incomes_amount_positive');
    DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_amount_positive');

    DB::table('incomes')->insert([
        'user_id' => $user->id,
        'amount' => 0,
        'source' => 'Legacy zero income',
        'income_date' => '2026-10-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('expenses')->insert([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 0,
        'description' => 'Legacy zero expense',
        'expense_date' => '2026-10-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacyIncomeId = (int) DB::table('incomes')->where('source', 'Legacy zero income')->value('id');
    $legacyExpenseId = (int) DB::table('expenses')->where('description', 'Legacy zero expense')->value('id');

    DB::statement('ALTER TABLE incomes ADD CONSTRAINT incomes_amount_positive CHECK (amount > 0) NOT VALID');
    DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0) NOT VALID');

    // Each rejected statement runs inside a savepoint so the expected
    // PostgreSQL error does not abort the enclosing test transaction.
    $expectRejected = function (callable $operation) {
        $rejected = false;

        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue('Expected the database to reject the invalid amount.');
    };

    // Not VALID skips the existing row but still enforces every new write.
    $expectRejected(fn () => DB::table('incomes')->insert([
        'user_id' => $user->id,
        'amount' => 0,
        'source' => 'New zero income',
        'income_date' => '2026-10-02',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('incomes')->where('id', $legacyIncomeId)->update(['amount' => -5]));

    $expectRejected(fn () => DB::table('expenses')->insert([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 0,
        'expense_date' => '2026-10-02',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('expenses')->where('id', $legacyExpenseId)->update(['amount' => -5]));

    // Recurring-income checks are validated immediately because the tables
    // were created by this feature.
    $schedule = RecurringIncome::create([
        'user_id' => $user->id,
        'name' => 'Constraint schedule',
        'amount' => 500,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
    ]);

    $linkedIncome = Income::factory()->create([
        'user_id' => $user->id,
        'amount' => 100,
        'income_date' => '2026-10-01',
    ]);

    $expectRejected(fn () => DB::table('recurring_incomes')->insert([
        'user_id' => $user->id,
        'name' => 'Bad amount',
        'amount' => 0,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('recurring_incomes')->insert([
        'user_id' => $user->id,
        'name' => 'Bad frequency',
        'amount' => 100,
        'frequency' => 'fortnightly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('recurring_incomes')->insert([
        'user_id' => $user->id,
        'name' => 'Bad status',
        'amount' => 100,
        'frequency' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-29',
        'status' => 'archived',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('recurring_incomes')->insert([
        'user_id' => $user->id,
        'name' => 'Reversed dates',
        'amount' => 100,
        'frequency' => 'weekly',
        'start_date' => '2026-10-29',
        'end_date' => '2026-10-01',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('recurring_income_occurrences')->insert([
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => '2026-10-01',
        'expected_amount' => 500,
        'status' => 'received',
        'income_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $expectRejected(fn () => DB::table('recurring_income_occurrences')->insert([
        'recurring_income_id' => $schedule->id,
        'occurrence_date' => '2026-10-01',
        'expected_amount' => 500,
        'status' => 'planned',
        'income_id' => $linkedIncome->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->assertDatabaseHas('incomes', ['id' => $legacyIncomeId, 'amount' => 0]);
    $this->assertDatabaseHas('expenses', ['id' => $legacyExpenseId, 'amount' => 0]);
});

// ----------- Budget uniqueness -----------

test('only one budget can exist per user category and month', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $payload = [
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10',
    ];

    $this->actingAs($user)->postJson('/api/budgets', $payload)->assertStatus(201);
    $this->assertDatabaseCount('budgets', 1);

    $duplicate = $this->actingAs($user)->postJson('/api/budgets', $payload);
    $duplicate
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->assertDatabaseCount('budgets', 1);
});

test('updating a budget cannot collide with another budget period', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $first = Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ]);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 700,
        'month_year' => '2026-11-01',
    ]);

    $this->actingAs($user)->putJson("/api/budgets/{$first->id}", ['month_year' => '2026-11'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('the database rejects duplicate budgets for the same user category and month', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $attributes = [
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ];

    Budget::create($attributes);

    $this->expectException(QueryException::class);
    Budget::create($attributes);
});

test('the database rejects two budgets in the same month recorded on different days', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ]);

    $this->expectException(QueryException::class);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 700,
        'month_year' => '2026-10-15',
    ]);
});

test('budgets submitted with any day in a month are normalized to the first day', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)->postJson('/api/budgets', [
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-15',
    ])->assertStatus(201);

    $budget = Budget::where('user_id', $user->id)
        ->where('category_id', $category->id)
        ->firstOrFail();

    expect($budget->month_year->toDateString())->toBe('2026-10-01');
});

test('a second budget for the same month is rejected even when the day differs', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)->postJson('/api/budgets', [
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ])->assertStatus(201);

    $this->actingAs($user)->postJson('/api/budgets', [
        'category_id' => $category->id,
        'limit_amount' => 700,
        'month_year' => '2026-10-15',
    ])->assertStatus(422)->assertJsonValidationErrors(['category_id']);

    $this->assertDatabaseCount('budgets', 1);
});

test('budgets reject invalid calendar dates and months', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    foreach (['2026-02-30', '2026-13', '2026-13-01', 'not-a-date'] as $month) {
        $this->actingAs($user)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'limit_amount' => 500,
            'month_year' => $month,
        ])->assertStatus(422)->assertJsonValidationErrors(['month_year']);
    }

    $this->assertDatabaseCount('budgets', 0);
});

test('a legacy budget stored on a non-first day still blocks a same-month duplicate', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-15',
    ]);

    $this->actingAs($user)->postJson('/api/budgets', [
        'category_id' => $category->id,
        'limit_amount' => 700,
        'month_year' => '2026-10-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['category_id']);

    $this->assertDatabaseCount('budgets', 1);
});
