<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\NetWorthValuation;
use App\Models\SavingsGoalContribution;
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

function netWorthPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Main account',
        'type' => 'asset',
        'category' => 'cash',
        'institution' => 'Example bank',
        'notes' => 'Manual estimate',
        'value' => '1000.00',
        'valuation_date' => '2026-10-01',
        'valuation_note' => 'Opening balance',
    ], $overrides);
}

function createNetWorthEntry($test, User $user, array $overrides = []): array
{
    return $test->actingAs($user)
        ->postJson('/api/net-worth', netWorthPayload($overrides))
        ->assertCreated()
        ->json('data');
}

test('net worth tracks manual assets liabilities precision and category totals', function () {
    $user = User::factory()->create();

    $cash = createNetWorthEntry($this, $user, [
        'name' => 'Cash account',
        'value' => '1500.50',
        'valuation_date' => '2026-08-31',
    ]);
    $investment = createNetWorthEntry($this, $user, [
        'name' => 'Index fund',
        'category' => 'investment',
        'value' => '500.25',
        'valuation_date' => '2026-08-31',
    ]);
    $loan = createNetWorthEntry($this, $user, [
        'name' => 'Car loan',
        'type' => 'liability',
        'category' => 'loan',
        'value' => '200.10',
        'valuation_date' => '2026-08-31',
    ]);

    expect($cash['latest_value'])->toBe('1500.50')
        ->and($cash['valuation_count'])->toBe(1)
        ->and($investment['latest_value'])->toBe('500.25')
        ->and($loan['latest_value'])->toBe('200.10')
        ->and($loan['type'])->toBe('liability')
        ->and($loan['category_label'])->toBe('Loan');

    $this->actingAs($user)->getJson('/api/net-worth?status=active&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.summary.total_assets', '2000.75')
        ->assertJsonPath('data.summary.total_liabilities', '200.10')
        ->assertJsonPath('data.summary.net_worth', '1800.65')
        ->assertJsonPath('data.summary.active_entry_count', 3)
        ->assertJsonPath('data.summary.unvalued_entry_count', 0)
        ->assertJsonPath('data.summary.category_totals.0.category', 'cash')
        ->assertJsonPath('data.summary.category_totals.0.total', '1500.50')
        ->assertJsonPath('data.summary.category_totals.1.category', 'investment')
        ->assertJsonPath('data.summary.category_totals.2.category', 'loan')
        ->assertJsonPath('data.entries.0.name', 'Car loan')
        ->assertJsonPath('data.timezone', 'UTC');

    // Precision is kept in integer cents, not binary floating point.
    $this->actingAs($user)->getJson('/api/net-worth?status=active')
        ->assertOk()
        ->assertJsonPath('data.summary.net_worth', '1800.65');
});

test('net worth valuations are append only and preserve history on edits', function () {
    $user = User::factory()->create();
    $entry = createNetWorthEntry($this, $user, [
        'name' => 'Savings',
        'value' => '1000.00',
        'valuation_date' => '2026-09-30',
    ]);

    $this->actingAs($user)->postJson("/api/net-worth/{$entry['id']}/valuations", [
        'value' => '1200.25',
        'valuation_date' => '2026-10-15',
        'note' => 'Monthly check',
    ])->assertCreated()
        ->assertJsonPath('data.latest_value', '1200.25')
        ->assertJsonPath('data.valuation_count', 2)
        ->assertJsonPath('data.valuations.0.value', '1200.25')
        ->assertJsonPath('data.valuations.1.value', '1000.00');

    $this->actingAs($user)->putJson("/api/net-worth/{$entry['id']}", [
        'name' => 'Emergency savings',
        'notes' => 'Renamed only; values stay in the ledger',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Emergency savings')
        ->assertJsonPath('data.latest_value', '1200.25')
        ->assertJsonPath('data.valuation_count', 2);

    $duplicate = $this->actingAs($user)->postJson("/api/net-worth/{$entry['id']}/valuations", [
        'value' => '1300.00',
        'valuation_date' => '2026-10-15',
    ]);
    $duplicate->assertStatus(409);
    expect(NetWorthValuation::where('net_worth_entry_id', $entry['id'])->count())->toBe(2);

    $this->actingAs($user)->postJson("/api/net-worth/{$entry['id']}/valuations", [
        'value' => '1300.00',
        'valuation_date' => '2026-10-21',
    ])->assertStatus(422);
    $this->actingAs($user)->postJson("/api/net-worth/{$entry['id']}/valuations", [
        'value' => '-1.00',
        'valuation_date' => '2026-10-19',
    ])->assertStatus(422);
    $this->actingAs($user)->postJson("/api/net-worth/{$entry['id']}/valuations", [
        'value' => '10.999',
        'valuation_date' => '2026-10-19',
    ])->assertStatus(422);

    $this->actingAs($user)->getJson("/api/net-worth/{$entry['id']}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Emergency savings')
        ->assertJsonPath('data.latest_value', '1200.25')
        ->assertJsonCount(2, 'data.valuations');
});

test('archiving preserves valuation history and removes an entry from totals until restored', function () {
    $user = User::factory()->create();
    $active = createNetWorthEntry($this, $user, [
        'name' => 'Active cash',
        'value' => '500.00',
        'valuation_date' => '2026-10-01',
    ]);
    $archived = createNetWorthEntry($this, $user, [
        'name' => 'Archived loan',
        'type' => 'liability',
        'category' => 'loan',
        'value' => '250.00',
        'valuation_date' => '2026-10-01',
    ]);

    $this->actingAs($user)->postJson("/api/net-worth/{$archived['id']}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.latest_value', '250.00')
        ->assertJsonPath('data.valuation_count', 1);

    $this->actingAs($user)->getJson('/api/net-worth?status=active')
        ->assertOk()
        ->assertJsonPath('data.summary.total_assets', '500.00')
        ->assertJsonPath('data.summary.total_liabilities', '0.00')
        ->assertJsonPath('data.summary.net_worth', '500.00');

    $this->actingAs($user)->getJson('/api/net-worth?status=archived')
        ->assertOk()
        ->assertJsonPath('data.entries.0.id', $archived['id'])
        ->assertJsonPath('data.entries.0.valuation_count', 1)
        ->assertJsonPath('data.summary.total_assets', '500.00');

    $this->actingAs($user)->putJson("/api/net-worth/{$archived['id']}", ['name' => 'Nope'])
        ->assertStatus(409);
    $this->actingAs($user)->postJson("/api/net-worth/{$archived['id']}/valuations", [
        'value' => '240.00',
        'valuation_date' => '2026-10-10',
    ])->assertStatus(409);

    $this->actingAs($user)->postJson("/api/net-worth/{$archived['id']}/restore")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.valuation_count', 1);

    $this->actingAs($user)->getJson('/api/net-worth?status=active')
        ->assertOk()
        ->assertJsonPath('data.summary.total_assets', '500.00')
        ->assertJsonPath('data.summary.total_liabilities', '250.00')
        ->assertJsonPath('data.summary.net_worth', '250.00');

    // Archiving is idempotent, while restoring an already active entry is not.
    $this->actingAs($user)->postJson("/api/net-worth/{$active['id']}/archive")->assertOk();
    $this->actingAs($user)->postJson("/api/net-worth/{$active['id']}/archive")->assertOk();
    $this->actingAs($user)->postJson("/api/net-worth/{$active['id']}/restore")->assertOk();
    $this->actingAs($user)->postJson("/api/net-worth/{$active['id']}/restore")->assertStatus(409);
});

test('net worth trend uses month end snapshots and excludes archived entries', function () {
    $user = User::factory()->create();
    $asset = createNetWorthEntry($this, $user, [
        'name' => 'Portfolio',
        'value' => '1000.00',
        'valuation_date' => '2026-08-31',
    ]);
    $liability = createNetWorthEntry($this, $user, [
        'name' => 'Loan',
        'type' => 'liability',
        'category' => 'loan',
        'value' => '200.00',
        'valuation_date' => '2026-08-31',
    ]);

    $this->actingAs($user)->postJson("/api/net-worth/{$asset['id']}/valuations", [
        'value' => '1100.00',
        'valuation_date' => '2026-09-30',
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/net-worth/{$asset['id']}/valuations", [
        'value' => '1200.00',
        'valuation_date' => '2026-10-15',
    ])->assertCreated();
    $this->actingAs($user)->postJson("/api/net-worth/{$liability['id']}/valuations", [
        'value' => '150.00',
        'valuation_date' => '2026-09-30',
    ])->assertCreated();

    $this->actingAs($user)->getJson('/api/net-worth/trend?months=3&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.sufficient_history', true)
        ->assertJsonPath('data.points.0.month', '2026-08')
        ->assertJsonPath('data.points.0.total_assets', '1000.00')
        ->assertJsonPath('data.points.0.total_liabilities', '200.00')
        ->assertJsonPath('data.points.0.net_worth', '800.00')
        ->assertJsonPath('data.points.1.month', '2026-09')
        ->assertJsonPath('data.points.1.total_assets', '1100.00')
        ->assertJsonPath('data.points.1.net_worth', '950.00')
        ->assertJsonPath('data.points.2.month', '2026-10')
        ->assertJsonPath('data.points.2.total_assets', '1200.00')
        ->assertJsonPath('data.points.2.net_worth', '1050.00')
        ->assertJsonPath('data.points.2.is_current_month', true);

    $this->actingAs($user)->postJson("/api/net-worth/{$liability['id']}/archive")->assertOk();

    $this->actingAs($user)->getJson('/api/net-worth/trend?months=3&as_of=2026-10-20')
        ->assertOk()
        ->assertJsonPath('data.points.0.total_liabilities', '0.00')
        ->assertJsonPath('data.points.2.total_liabilities', '0.00')
        ->assertJsonPath('data.points.2.net_worth', '1200.00');

    $this->actingAs($user)->getJson('/api/net-worth/trend?months=1')->assertStatus(422);
    $this->actingAs($user)->getJson('/api/net-worth/trend?months=25')->assertStatus(422);
});

test('net worth validation ownership and read only boundaries are enforced', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherEntry = createNetWorthEntry($this, $other, [
        'name' => 'Other user account',
        'value' => '999.00',
    ]);

    $this->actingAs($user)->getJson('/api/net-worth')
        ->assertOk()
        ->assertJsonPath('data.entries', [])
        ->assertJsonPath('data.summary.total_assets', '0.00');

    $this->actingAs($user)->getJson("/api/net-worth/{$otherEntry['id']}")->assertStatus(403);
    $this->actingAs($user)->putJson("/api/net-worth/{$otherEntry['id']}", ['name' => 'Hijack'])->assertStatus(403);
    $this->actingAs($user)->postJson("/api/net-worth/{$otherEntry['id']}/archive")->assertStatus(403);
    $this->actingAs($user)->postJson("/api/net-worth/{$otherEntry['id']}/valuations", [
        'value' => '1.00',
        'valuation_date' => '2026-10-01',
    ])->assertStatus(403);

    $this->actingAs($user)->postJson('/api/net-worth', netWorthPayload([
        'type' => 'asset',
        'category' => 'loan',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/net-worth', netWorthPayload([
        'name' => '',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/net-worth', netWorthPayload([
        'value' => '-5.00',
    ]))->assertStatus(422);
    $this->actingAs($user)->postJson('/api/net-worth', netWorthPayload([
        'valuation_date' => '2026-10-21',
    ]))->assertStatus(422);
    $this->actingAs($user)->getJson('/api/net-worth?as_of=2026-02-30')->assertStatus(422);

    $before = [
        'incomes' => Income::count(),
        'expenses' => Expense::count(),
        'contributions' => SavingsGoalContribution::count(),
    ];

    $this->actingAs($user)->postJson('/api/net-worth', netWorthPayload([
        'name' => 'Zero balance loan',
        'type' => 'liability',
        'category' => 'loan',
        'value' => '0.00',
    ]))->assertCreated();

    expect([
        'incomes' => Income::count(),
        'expenses' => Expense::count(),
        'contributions' => SavingsGoalContribution::count(),
    ])->toBe($before);

    auth()->forgetGuards();
    $this->getJson('/api/net-worth')->assertStatus(401);
});

test('net worth requires authentication', function () {
    $this->getJson('/api/net-worth')->assertStatus(401);
    $this->postJson('/api/net-worth', netWorthPayload())->assertStatus(401);
});
