<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\SavingsGoal;
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

function savingsGoalPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Emergency fund',
        'description' => 'Six months of essentials',
        'target_amount' => 1200.00,
        'target_date' => '2027-06-30',
        'notes' => 'Manual tracking only',
    ], $overrides);
}

function makeSavingsGoal(User $user, array $overrides = []): SavingsGoal
{
    return SavingsGoal::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Holiday fund',
        'target_amount' => 100.00,
        'status' => SavingsGoal::STATUS_ACTIVE,
    ], $overrides));
}

test('savings goal endpoints require authentication', function () {
    $this->getJson('/api/savings-goals')->assertStatus(401);
    $this->postJson('/api/savings-goals', savingsGoalPayload())->assertStatus(401);
});

test('a user can create and view a savings goal with computed progress', function () {
    $user = User::factory()->create();

    $created = $this->actingAs($user)
        ->postJson('/api/savings-goals', savingsGoalPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.target_amount', '1200.00')
        ->assertJsonPath('data.saved_amount', '0.00')
        ->assertJsonPath('data.remaining_amount', '1200.00')
        ->assertJsonPath('data.progress_percent', 0)
        ->json('data');

    $this->actingAs($user)
        ->getJson("/api/savings-goals/{$created['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Emergency fund')
        ->assertJsonPath('data.contributions', []);
});

test('savings goals are owner scoped', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $goal = makeSavingsGoal($owner);

    $this->actingAs($other)
        ->getJson("/api/savings-goals/{$goal->id}")
        ->assertStatus(403);
    $this->actingAs($other)
        ->putJson("/api/savings-goals/{$goal->id}", ['name' => 'Hijacked'])
        ->assertStatus(403);
    $this->actingAs($other)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 10])
        ->assertStatus(403);

    $this->actingAs($other)->getJson('/api/savings-goals')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.goals');
});

test('goal amounts and dates are validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/savings-goals', savingsGoalPayload(['target_amount' => 0]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_amount');
    $this->actingAs($user)
        ->postJson('/api/savings-goals', savingsGoalPayload(['target_amount' => -1]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_amount');
    $this->actingAs($user)
        ->postJson('/api/savings-goals', savingsGoalPayload(['target_date' => '2026-02-30']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_date');
});

test('contributions update progress and never create expense or income rows', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user, ['target_amount' => 200]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", [
            'amount' => 50.25,
            'contributed_at' => '2026-10-19',
            'note' => 'Payday transfer',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.saved_amount', '50.25')
        ->assertJsonPath('data.progress_percent', 25.13)
        ->assertJsonPath('data.remaining_amount', '149.75')
        ->assertJsonPath('data.contribution_count', 1)
        ->assertJsonPath('data.contributions.0.type', 'contribution');

    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseCount('incomes', 0);
    $this->assertSame(0, Expense::count());
    $this->assertSame(0, Income::count());
});

test('a goal completes when the saved balance reaches its target', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user, ['target_amount' => 100]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 40])
        ->assertJsonPath('data.status', 'active');

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 60])
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed_at', fn ($value) => $value !== null);

    $this->assertDatabaseHas('savings_goals', [
        'id' => $goal->id,
        'status' => 'completed',
    ]);
});

test('corrections are explicit positive ledger entries and cannot overdraw', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user, ['target_amount' => 100]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 80])
        ->assertStatus(201)
        ->assertJsonPath('data.saved_amount', '80.00');

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/corrections", [
            'amount' => 90,
            'note' => 'Too large',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/corrections", ['amount' => -5, 'note' => 'Bad'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/corrections", ['amount' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors('note');

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/corrections", [
            'amount' => 30,
            'note' => 'Duplicate transfer removed',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.saved_amount', '50.00')
        ->assertJsonPath('data.remaining_amount', '50.00')
        ->assertJsonPath('data.contributions.0.type', 'correction');

    $this->assertDatabaseCount('savings_goal_contributions', 2);
});

test('editing a target keeps contribution history and reconciles status', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user, ['target_amount' => 100]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 100])
        ->assertJsonPath('data.status', 'completed');

    $this->actingAs($user)
        ->putJson("/api/savings-goals/{$goal->id}", [
            'target_amount' => 150,
            'target_date' => '2027-01-01',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.saved_amount', '100.00')
        ->assertJsonPath('data.contribution_count', 1);

    $this->actingAs($user)
        ->putJson("/api/savings-goals/{$goal->id}", ['target_amount' => 90])
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseCount('savings_goal_contributions', 1);
});

test('archiving preserves history and makes the goal read only until restored', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user, ['target_amount' => 100]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 25]);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/archive")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.saved_amount', '25.00')
        ->assertJsonPath('data.contribution_count', 1);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 10])
        ->assertStatus(409);
    $this->actingAs($user)
        ->putJson("/api/savings-goals/{$goal->id}", ['name' => 'Changed'])
        ->assertStatus(409);

    $this->actingAs($user)
        ->getJson('/api/savings-goals?status=archived')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.goals')
        ->assertJsonPath('data.goals.0.contribution_count', 1);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.saved_amount', '25.00');
});

test('contribution dates cannot be in the future and amounts cannot be negative', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user);

    $this->actingAs($user)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", [
            'amount' => 10,
            'contributed_at' => '2026-10-21',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contributed_at');

    foreach ([0, -10] as $amount) {
        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => $amount])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }
});

test('replaying a contribution with the same idempotency key creates one ledger entry', function () {
    $user = User::factory()->create();
    $goal = makeSavingsGoal($user);
    $headers = ['Idempotency-Key' => 'savings-goal-contribution-001'];

    $first = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 20])
        ->assertStatus(201)
        ->json('data');

    $replay = $this->actingAs($user)
        ->withHeaders($headers)
        ->postJson("/api/savings-goals/{$goal->id}/contributions", ['amount' => 20])
        ->assertStatus(201)
        ->json('data');

    expect($replay['saved_amount'])->toBe($first['saved_amount'])
        ->and($replay['contribution_count'])->toBe(1);
    $this->assertDatabaseCount('savings_goal_contributions', 1);
});

test('goal list summary includes only current goals and separates archived goals', function () {
    $user = User::factory()->create();
    makeSavingsGoal($user, [
        'name' => 'Active goal',
        'target_amount' => 500,
        'status' => SavingsGoal::STATUS_ACTIVE,
    ]);
    makeSavingsGoal($user, [
        'name' => 'Done goal',
        'target_amount' => 100,
        'status' => SavingsGoal::STATUS_COMPLETED,
    ]);
    makeSavingsGoal($user, [
        'name' => 'Archived goal',
        'target_amount' => 900,
        'status' => SavingsGoal::STATUS_ARCHIVED,
    ]);

    $this->actingAs($user)
        ->getJson('/api/savings-goals')
        ->assertStatus(200)
        ->assertJsonCount(3, 'data.goals')
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.summary.completed_count', 1)
        ->assertJsonPath('data.summary.archived_count', 1)
        ->assertJsonPath('data.summary.total_target', '600.00')
        ->assertJsonPath('data.summary.total_saved', '0.00');
});
