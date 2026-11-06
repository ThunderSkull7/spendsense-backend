<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('full data export requires authentication', function () {
    $response = $this->getJson('/api/export/all');
    $response->assertStatus(401);
});

test('full data export returns structured backup with versioned metadata and owner scoped records', function () {
    $user = User::factory()->create([
        'name'  => 'Test Backup User',
        'email' => 'backup@example.com',
    ]);

    $category = Category::create([
        'user_id' => $user->id,
        'name'    => 'Backup Test Cat',
        'type'    => 'expense',
    ]);

    $expense = Expense::create([
        'user_id'      => $user->id,
        'category_id'  => $category->id,
        'amount'       => 1500.00,
        'description'  => 'Backup Expense item',
        'expense_date' => '2026-11-01',
    ]);

    $income = Income::create([
        'user_id'     => $user->id,
        'amount'      => 45000.00,
        'source'      => 'Primary Employer',
        'income_date' => '2026-11-01',
        'frequency'   => 'monthly',
    ]);

    // Foreign user record must NOT appear
    $otherUser = User::factory()->create(['email' => 'other@example.com']);
    $otherCategory = Category::create([
        'user_id' => $otherUser->id,
        'name'    => 'Other Category',
        'type'    => 'expense',
    ]);
    Expense::create([
        'user_id'      => $otherUser->id,
        'category_id'  => $otherCategory->id,
        'amount'       => 999.00,
        'description'  => 'Foreign Expense',
        'expense_date' => '2026-11-01',
    ]);

    $response = $this->actingAs($user)->getJson('/api/export/all');

    $response->assertStatus(200)
        ->assertHeader('Content-Disposition')
        ->assertJsonPath('meta.format', 'spendsense-user-data')
        ->assertJsonPath('meta.format_version', 1)
        ->assertJsonPath('account.id', $user->id)
        ->assertJsonPath('account.email', 'backup@example.com');

    $data = $response->json();

    // Verify expenses count and values
    expect(count($data['expenses']))->toBe(1);
    expect($data['expenses'][0]['description'])->toBe('Backup Expense item');

    // Verify income count and values
    expect(count($data['incomes']))->toBe(1);
    expect($data['incomes'][0]['source'])->toBe('Primary Employer');

    // Verify other user expense is excluded
    $allExpenseDescriptions = array_column($data['expenses'], 'description');
    expect($allExpenseDescriptions)->not->toContain('Foreign Expense');

    // Verify no password or credential leaks
    expect($data['account'])->not->toHaveKey('password');
    expect($data['account'])->not->toHaveKey('remember_token');
});
