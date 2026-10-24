<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\IdempotencyRecord;
use App\Models\Income;
use App\Models\RecurringIncome;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function stageOneExpensePayload(int $categoryId, array $overrides = []): array
{
    return array_merge([
        'amount' => 42.50,
        'category_id' => $categoryId,
        'expense_date' => '2026-10-20',
        'description' => 'Stage one safety test',
    ], $overrides);
}

function stageOneIncomePayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 1250.00,
        'source' => 'Stage one salary',
        'income_date' => '2026-10-20',
        'frequency' => 'one-time',
    ], $overrides);
}

// ----------- Recoverable deletion -----------

test('a soft-deleted expense is hidden, listed in trash, and restorable by its owner', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory($user);
    $expense = Expense::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'amount' => 42.50,
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(204);

    $this->assertSoftDeleted('expenses', ['id' => $expense->id]);

    $this->actingAs($user)
        ->getJson('/api/expenses')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 0);

    $this->actingAs($user)
        ->getJson('/api/expenses/trash')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $expense->id);

    $this->actingAs($user)
        ->postJson("/api/expenses/{$expense->id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $expense->id);

    $this->assertNotSoftDeleted('expenses', ['id' => $expense->id]);

    $this->actingAs($user)
        ->getJson('/api/expenses')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1);
});

test('trash and restore are scoped to the authenticated owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $category = makeExpenseCategory($owner);
    $expense = Expense::factory()->create([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'amount' => 10,
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(204);

    $this->actingAs($intruder)
        ->getJson('/api/expenses/trash')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 0);

    $this->actingAs($intruder)
        ->postJson("/api/expenses/{$expense->id}/restore")
        ->assertStatus(404);

    $this->actingAs($intruder)
        ->getJson("/api/expenses/{$expense->id}")
        ->assertStatus(404);

    $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
});

test('a soft-deleted income is excluded from lists and analytics and is restorable', function () {
    Carbon::setTestNow('2026-10-20 12:00:00');

    $user = User::factory()->create();
    $income = Income::factory()->create([
        'user_id' => $user->id,
        'amount' => 1250,
        'income_date' => '2026-10-20',
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/incomes/{$income->id}")
        ->assertStatus(204);

    $this->actingAs($user)
        ->getJson('/api/incomes')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 0);

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?year=2026&month=10')
        ->assertStatus(200)
        ->assertJsonPath('data.total_income', 0);

    $this->actingAs($user)
        ->getJson('/api/incomes/trash')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $income->id);

    $this->actingAs($user)
        ->postJson("/api/incomes/{$income->id}/restore")
        ->assertStatus(200);

    $this->actingAs($user)
        ->getJson('/api/analytics/cash-flow?year=2026&month=10')
        ->assertStatus(200)
        ->assertJsonPath('data.total_income', 1250);
});

test('trash listing is paginated so older deletions remain recoverable', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    foreach (range(1, 16) as $index) {
        $expense = Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => $index,
            'expense_date' => '2026-10-20',
        ]);
        $expense->delete();
    }

    $this->actingAs($user)
        ->getJson('/api/expenses/trash?page=1')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 16)
        ->assertJsonPath('data.last_page', 2)
        ->assertJsonCount(15, 'data.data');

    $this->actingAs($user)
        ->getJson('/api/expenses/trash?page=2')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.data');
});

test('trash and restore endpoints require authentication', function () {
    $this->getJson('/api/expenses/trash')->assertStatus(401);
    $this->postJson('/api/expenses/1/restore')->assertStatus(401);
    $this->getJson('/api/incomes/trash')->assertStatus(401);
    $this->postJson('/api/incomes/1/restore')->assertStatus(401);
});

test('the income list does not include an unnecessary user object', function () {
    $user = User::factory()->create();
    Income::factory()->create([
        'user_id' => $user->id,
        'income_date' => '2026-10-20',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/incomes')
        ->assertStatus(200);

    expect($response->json('data.data.0'))->not->toHaveKey('user');
});

// ----------- Idempotent creation -----------

test('repeating an expense request with the same idempotency key returns the original response', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $payload = stageOneExpensePayload($category->id);
    $key = 'expense-replay-001';

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', $payload)
        ->assertStatus(201)
        ->assertHeaderMissing('Idempotent-Replay');

    $second = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', $payload)
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Expense::query()->count())->toBe(1);
    expect(IdempotencyRecord::query()->count())->toBe(1);
});

test('reusing an idempotency key with a different payload is rejected without a second write', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $key = 'expense-conflict-001';

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload($category->id, ['amount' => 10]))
        ->assertStatus(201);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload($category->id, ['amount' => 11]))
        ->assertStatus(409);

    expect(Expense::query()->count())->toBe(1);
});

test('idempotency keys are scoped by user and operation', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $category = makeExpenseCategory();
    $key = 'shared-key-001';

    $this->actingAs($firstUser)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload($category->id))
        ->assertStatus(201);

    $this->actingAs($secondUser)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload($category->id))
        ->assertStatus(201);

    $this->actingAs($firstUser)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/incomes', stageOneIncomePayload())
        ->assertStatus(201);

    expect(Expense::query()->count())->toBe(2);
    expect(Income::query()->count())->toBe(1);
    expect(IdempotencyRecord::query()->count())->toBe(3);
});

test('malformed idempotency keys are rejected before any write', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'not a valid key')
        ->postJson('/api/expenses', stageOneExpensePayload($category->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    expect(Expense::query()->count())->toBe(0);
});

test('a valid idempotency key without a body change is replayed for income creation', function () {
    $user = User::factory()->create();
    $key = 'income-replay-001';

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/incomes', stageOneIncomePayload())
        ->assertStatus(201);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/incomes', stageOneIncomePayload())
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Income::query()->count())->toBe(1);
});

test('budget creation is protected by the idempotency middleware', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $key = 'budget-replay-001';
    $payload = [
        'category_id' => $category->id,
        'limit_amount' => 500.00,
        'month_year' => '2026-10',
    ];

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/budgets', $payload)
        ->assertStatus(201);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/budgets', $payload)
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Budget::query()->count())->toBe(1);
});

test('recurring schedule creation and receipt are protected by idempotency keys', function () {
    $user = User::factory()->create();
    $scheduleKey = 'recurring-replay-001';
    $payload = [
        'name' => 'Stage one recurring test',
        'amount' => 500.00,
        'frequency' => 'weekly',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-15',
        'notes' => 'Stage one safety test',
    ];

    $first = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $scheduleKey)
        ->postJson('/api/recurring-incomes', $payload)
        ->assertStatus(201);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $scheduleKey)
        ->postJson('/api/recurring-incomes', $payload)
        ->assertStatus(201)
        ->assertHeader('Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(RecurringIncome::query()->count())->toBe(1);

    $scheduleId = $first->json('data.id');
    $occurrenceId = $this->actingAs($user)
        ->getJson("/api/recurring-incomes/{$scheduleId}/occurrences")
        ->assertStatus(200)
        ->json('data.occurrences.0.id');

    $receiptKey = 'receipt-replay-001';
    $receipt = [
        'actual_amount' => 450.00,
        'actual_received_date' => '2026-11-01',
    ];

    $firstReceipt = $this->actingAs($user)
        ->withHeader('Idempotency-Key', $receiptKey)
        ->postJson("/api/recurring-incomes/{$scheduleId}/occurrences/{$occurrenceId}/receive", $receipt)
        ->assertStatus(200);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $receiptKey)
        ->postJson("/api/recurring-incomes/{$scheduleId}/occurrences/{$occurrenceId}/receive", $receipt)
        ->assertStatus(200)
        ->assertHeader('Idempotent-Replay', 'true')
        ->assertJsonPath('data.income_id', $firstReceipt->json('data.income_id'));

    expect(Income::query()->count())->toBe(1);
    expect(IdempotencyRecord::query()->count())->toBe(2);
});

test('a failed request does not consume its idempotency key', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $key = 'retry-after-validation-001';

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload(999999))
        ->assertStatus(422);

    expect(IdempotencyRecord::query()->count())->toBe(0);
    expect(Expense::query()->count())->toBe(0);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/expenses', stageOneExpensePayload($category->id))
        ->assertStatus(201);

    expect(Expense::query()->count())->toBe(1);
});

// ----------- Budget and category integrity -----------

test('budgets reject zero limits and income categories', function () {
    $user = User::factory()->create();
    $expenseCategory = makeExpenseCategory();
    $incomeCategory = makeIncomeCategory();

    $this->actingAs($user)
        ->postJson('/api/budgets', [
            'category_id' => $incomeCategory->id,
            'limit_amount' => 500,
            'month_year' => '2026-10',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Budget category must be of type expense.');

    $this->actingAs($user)
        ->postJson('/api/budgets', [
            'category_id' => $expenseCategory->id,
            'limit_amount' => 0,
            'month_year' => '2026-10',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('limit_amount');

    $this->actingAs($user)
        ->postJson('/api/budgets', [
            'category_id' => $expenseCategory->id,
            'limit_amount' => 500.00,
            'month_year' => '2026-10',
        ])
        ->assertStatus(201);
});

test('a referenced category cannot change type', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory($user);
    $expense = Expense::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", [
            'name' => $category->name,
            'type' => 'income',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change category type: category has associated expenses.');

    expect($category->fresh()->type)->toBe('expense');

    $expense->delete();

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", [
            'name' => $category->name,
            'type' => 'income',
        ])
        ->assertStatus(422);
});

test('a category referenced by a budget cannot change type or be deleted', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory($user);
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ]);

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", [
            'name' => $category->name,
            'type' => 'income',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change category type: category has associated budgets.');

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete category: category has associated budgets.');

    expect($category->fresh()->type)->toBe('expense');
});

test('a category with only a soft-deleted expense cannot be deleted', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory($user);
    $expense = Expense::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'expense_date' => '2026-10-20',
    ]);

    $expense->delete();

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete category: category has associated expenses.');

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('a budget references an expense category only', function () {
    $user = User::factory()->create();
    $expenseCategory = makeExpenseCategory();
    $incomeCategory = makeIncomeCategory();
    $budget = Budget::create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'limit_amount' => 500,
        'month_year' => '2026-10-01',
    ]);

    $this->actingAs($user)
        ->putJson("/api/budgets/{$budget->id}", [
            'category_id' => $incomeCategory->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Budget category must be of type expense.');
});
