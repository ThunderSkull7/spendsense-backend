<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('debt endpoints require authentication', function () {
    $this->getJson('/api/debts')->assertStatus(401);
    $this->postJson('/api/debts', ['name' => 'Car Loan', 'original_amount' => 500000])->assertStatus(401);
    $this->getJson('/api/debts/1')->assertStatus(401);
});

test('a user can create, view, and list debts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Education Loan',
        'original_amount' => 500000.00,
        'current_balance' => 450000.00,
        'lender'          => 'National Bank',
        'interest_rate'   => 8.5,
        'minimum_payment' => 10000.00,
        'notes'           => 'Tuition fees repayment',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Education Loan')
        ->assertJsonPath('data.original_principal', '500000.00')
        ->assertJsonPath('data.current_balance', '450000.00')
        ->assertJsonPath('data.repaid_amount', '50000.00')
        ->assertJsonPath('data.repayment_progress_percent', fn ($val) => (float) $val === 10.0)
        ->assertJsonPath('data.lender', 'National Bank')
        ->assertJsonPath('data.interest_rate', 8.5)
        ->assertJsonPath('data.status', 'active');

    $debtId = $response->json('data.id');

    $listResponse = $this->actingAs($user)->getJson('/api/debts');
    $listResponse->assertOk()
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.summary.total_original', '500000.00')
        ->assertJsonPath('data.summary.total_current_balance', '450000.00')
        ->assertJsonPath('data.summary.total_repaid', '50000.00')
        ->assertJsonCount(1, 'data.debts');

    $singleResponse = $this->actingAs($user)->getJson("/api/debts/{$debtId}");
    $singleResponse->assertOk()
        ->assertJsonPath('data.id', $debtId)
        ->assertJsonPath('data.repayments.0.balance_amount', '450000.00');
});

test('debts are owner scoped and foreign access returns 404', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $debt = $this->actingAs($owner)->postJson('/api/debts', [
        'name'            => 'Private Loan',
        'original_amount' => 100000.00,
    ])->json('data');

    $this->actingAs($stranger)->getJson("/api/debts/{$debt['id']}")->assertStatus(404);
    $this->actingAs($stranger)->postJson("/api/debts/{$debt['id']}/repayments", ['amount' => 5000])->assertStatus(404);
    $this->actingAs($stranger)->postJson("/api/debts/{$debt['id']}/archive")->assertStatus(404);

    $strangerList = $this->actingAs($stranger)->getJson('/api/debts');
    $strangerList->assertOk()->assertJsonCount(0, 'data.debts');
});

test('validation rejects invalid amounts and balances', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Invalid Debt',
        'original_amount' => -500,
    ])->assertStatus(422)->assertJsonValidationErrors(['original_amount']);

    $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Over-balanced',
        'original_amount' => 10000,
        'current_balance' => 20000,
    ])->assertStatus(422)->assertJsonValidationErrors(['current_balance']);
});

test('recording a repayment decreases balance without inflating expenses or incomes', function () {
    $user = User::factory()->create();

    $debt = $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Personal Loan',
        'original_amount' => 100000.00,
        'current_balance' => 100000.00,
        'start_date'      => '2026-01-01',
    ])->json('data');

    $initialExpenseCount = Expense::count();
    $initialIncomeCount = Income::count();

    $repayResponse = $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/repayments", [
        'amount'       => 25000.00,
        'payment_date' => '2026-02-01',
        'note'         => 'First installment',
    ]);

    $repayResponse->assertStatus(201)
        ->assertJsonPath('data.current_balance', '75000.00')
        ->assertJsonPath('data.repaid_amount', '25000.00')
        ->assertJsonPath('data.repayment_progress_percent', fn ($val) => (float) $val === 25.0)
        ->assertJsonPath('data.status', 'active');

    // Financial integrity: Debt repayments must NOT create rows in expenses or incomes table
    expect(Expense::count())->toBe($initialExpenseCount);
    expect(Income::count())->toBe($initialIncomeCount);
});

test('repayment exceeding balance is rejected', function () {
    $user = User::factory()->create();

    $debt = $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Small Loan',
        'original_amount' => 5000.00,
    ])->json('data');

    $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/repayments", [
        'amount' => 10000.00,
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

test('debt completes when balance reaches zero', function () {
    $user = User::factory()->create();

    $debt = $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Gadget Loan',
        'original_amount' => 20000.00,
        'current_balance' => 20000.00,
        'start_date'      => '2026-03-01',
    ])->json('data');

    $completed = $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/repayments", [
        'amount'       => 20000.00,
        'payment_date' => '2026-04-01',
        'note'         => 'Final payoff',
    ]);

    $completed->assertStatus(201)
        ->assertJsonPath('data.current_balance', '0.00')
        ->assertJsonPath('data.repaid_amount', '20000.00')
        ->assertJsonPath('data.repayment_progress_percent', fn ($val) => (float) $val === 100.0)
        ->assertJsonPath('data.status', 'completed');

    // Attempting further repayment on a completed debt fails
    $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/repayments", [
        'amount' => 1000.00,
    ])->assertStatus(409);
});

test('archiving preserves history and makes debt read-only', function () {
    $user = User::factory()->create();

    $debt = $this->actingAs($user)->postJson('/api/debts', [
        'name'            => 'Old Debt',
        'original_amount' => 50000.00,
    ])->json('data');

    $archived = $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/archive");
    $archived->assertOk()->assertJsonPath('data.status', 'archived');

    // Cannot record repayment on archived debt
    $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/repayments", [
        'amount' => 5000.00,
    ])->assertStatus(409);

    // Restoring reactivates it
    $restored = $this->actingAs($user)->postJson("/api/debts/{$debt['id']}/restore");
    $restored->assertOk()->assertJsonPath('data.status', 'active');
});
