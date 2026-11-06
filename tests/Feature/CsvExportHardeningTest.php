<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('csv cell utility neutralizes formula injection payloads including pipes and percent variables', function () {
    expect(Csv::cell('=cmd|calc!A1'))->toBe("'=cmd|calc!A1");
    expect(Csv::cell('@SUM(A1:A10)'))->toBe("'@SUM(A1:A10)");
    expect(Csv::cell('+12345'))->toBe("'+12345");
    expect(Csv::cell('-cmd|calc'))->toBe("'-cmd|calc");
    expect(Csv::cell('|calc'))->toBe("'|calc");
    expect(Csv::cell('%COMSPEC%'))->toBe("'%COMSPEC%");
    expect(Csv::cell("  =1+1"))->toBe("'  =1+1");
    expect(Csv::cell("\t=1+1"))->toBe("'\t=1+1");
    expect(Csv::cell("\r\n-cmd"))->toBe("'\r\n-cmd");
    expect(Csv::cell("  |calc"))->toBe("'  |calc");
});

test('csv cell utility preserves legitimate negative and positive numbers', function () {
    expect(Csv::cell('-123.45'))->toBe('-123.45');
    expect(Csv::cell('-100'))->toBe('-100');
    expect(Csv::cell('0'))->toBe('0');
    expect(Csv::cell('120.00'))->toBe('120.00');
    expect(Csv::cell('-0.50'))->toBe('-0.50');
    expect(Csv::cell(-50.25))->toBe('-50.25');
    expect(Csv::cell(100))->toBe('100');
    expect(Csv::cell(null))->toBe('');
    expect(Csv::cell('Plain description'))->toBe('Plain description');
});

test('transaction export includes utf8 bom header and neutralizes dangerous formulas in text fields', function () {
    $user = User::factory()->create();
    $cat = Category::query()->firstOrCreate(
        ['name' => 'Bills', 'type' => 'expense', 'user_id' => null],
        ['is_default' => true]
    );

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'amount' => '150.50',
        'description' => '=cmd|calc!A1',
        'expense_date' => '2026-10-05',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'amount' => '45.00',
        'description' => '|powershell -Command calc',
        'expense_date' => '2026-10-06',
    ]);

    Income::create([
        'user_id' => $user->id,
        'amount' => '2500.00',
        'source' => '@SUM(B1:B10)',
        'income_date' => '2026-10-01',
        'frequency' => 'monthly',
        'notes' => '+447000000000',
    ]);

    $response = $this->actingAs($user)->get('/api/transactions/export');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();

    // UTF-8 BOM must be present at the very beginning of the output stream
    expect(str_starts_with($content, Csv::BOM))->toBeTrue();

    // Neutralization checks
    expect($content)->toContain("'=cmd|calc!A1");
    expect($content)->toContain("'|powershell -Command calc");
    expect($content)->toContain("'@SUM(B1:B10)");
    expect($content)->toContain("'+447000000000");

    // Amount preservation: expense is signed negative, income is positive
    expect($content)->toContain('-150.50');
    expect($content)->toContain('2500.00');
});

test('transaction export respects active filters and date ranges', function () {
    $user = User::factory()->create();
    $cat = Category::query()->firstOrCreate(
        ['name' => 'General', 'type' => 'expense', 'user_id' => null],
        ['is_default' => true]
    );

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'amount' => '50.00',
        'description' => 'Inside October range',
        'expense_date' => '2026-10-15',
    ]);

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'amount' => '75.00',
        'description' => 'Outside range September',
        'expense_date' => '2026-09-15',
    ]);

    $response = $this->actingAs($user)->get('/api/transactions/export?from=2026-10-01&to=2026-10-31&type=expense');
    $response->assertOk();

    $content = $response->streamedContent();
    expect($content)->toContain('Inside October range');
    expect($content)->not->toContain('Outside range September');
});

test('transaction export emits headers only when no records match', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/api/transactions/export?type=expense');
    $response->assertOk();

    $content = $response->streamedContent();
    expect(str_starts_with($content, Csv::BOM))->toBeTrue();

    // Strip BOM and split lines
    $lines = array_filter(explode("\n", str_replace(Csv::BOM, '', trim($content))));
    expect($lines)->toHaveCount(1);
    expect($lines[0])->toBe('Type,Date,Description,"Category / source",Notes,Amount,"Signed amount"');
});

test('transaction export isolates user data and enforces authentication', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $cat = Category::query()->firstOrCreate(
        ['name' => 'Misc', 'type' => 'expense', 'user_id' => null],
        ['is_default' => true]
    );

    Expense::create([
        'user_id' => $userB->id,
        'category_id' => $cat->id,
        'amount' => '999.00',
        'description' => 'User B Secret Expense',
        'expense_date' => '2026-10-10',
    ]);

    // Unauthenticated request is rejected
    $this->get('/api/transactions/export')->assertStatus(401);

    // User A cannot see User B's records
    $response = $this->actingAs($userA)->get('/api/transactions/export');
    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->not->toContain('User B Secret Expense');
});

test('reports export includes utf8 bom header and is owner scoped', function () {
    $user = User::factory()->create();
    $cat = Category::query()->firstOrCreate(
        ['name' => 'Food', 'type' => 'expense', 'user_id' => null],
        ['is_default' => true]
    );

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'amount' => '80.00',
        'description' => 'Groceries',
        'expense_date' => '2026-10-10',
    ]);

    $response = $this->actingAs($user)->get('/api/reports/export?from=2026-10-01&to=2026-10-31');
    $response->assertOk();

    $content = $response->streamedContent();
    expect(str_starts_with($content, Csv::BOM))->toBeTrue();
    expect($content)->toContain('SpendSense financial report');
    expect($content)->toContain('80.00');
});

