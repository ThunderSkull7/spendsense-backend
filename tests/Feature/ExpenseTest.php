<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----------- helpers -----------

function validPayload(int $categoryId): array
{
    return [
        'amount'       => 99.99,
        'category_id'  => $categoryId,
        'expense_date' => '2025-01-15',
        'description'  => 'Test expense',
    ];
}

// ----------- POST /api/expenses -----------

test('authenticated user can create an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();

    $response = $this->actingAs($user)
        ->postJson('/api/expenses', validPayload($category->id));

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '99.99');

    $this->assertDatabaseHas('expenses', [
        'user_id'     => $user->id,
        'category_id' => $category->id,
        'amount'      => 99.99,
    ]);
});

test('unauthenticated user cannot create an expense', function () {
    $category = makeExpenseCategory();

    $this->postJson('/api/expenses', validPayload($category->id))
        ->assertStatus(401);
});

test('store rejects non-expense category type', function () {
    $user     = User::factory()->create();
    $category = makeIncomeCategory();

    $this->actingAs($user)
        ->postJson('/api/expenses', validPayload($category->id))
        ->assertStatus(422);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/expenses', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'category_id', 'expense_date']);
});

test('store rejects negative amount', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)
        ->postJson('/api/expenses', array_merge(validPayload($category->id), ['amount' => -10]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('store rejects invalid date format', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)
        ->postJson('/api/expenses', array_merge(validPayload($category->id), ['expense_date' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expense_date']);
});

test('store rejects non-existent category_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/expenses', array_merge(validPayload(9999), ['category_id' => 9999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('store does not trust client-supplied user_id', function () {
    $owner  = User::factory()->create();
    $other  = User::factory()->create();
    $category = makeExpenseCategory();

    $payload = array_merge(validPayload($category->id), ['user_id' => $other->id]);

    $response = $this->actingAs($owner)
        ->postJson('/api/expenses', $payload);

    $response->assertStatus(201);

    $this->assertDatabaseHas('expenses', [
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseMissing('expenses', [
        'user_id' => $other->id,
    ]);
});

// ----------- GET /api/expenses -----------

test('authenticated user can list their expenses paginated', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();

    Expense::factory()->count(3)->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/expenses');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']]);
});

test('unauthenticated user cannot list expenses', function () {
    $this->getJson('/api/expenses')->assertStatus(401);
});

test('index only returns expenses belonging to authenticated user', function () {
    $userA    = User::factory()->create();
    $userB    = User::factory()->create();
    $category = makeExpenseCategory();

    Expense::factory()->count(2)->create(['user_id' => $userA->id, 'category_id' => $category->id]);
    Expense::factory()->count(3)->create(['user_id' => $userB->id, 'category_id' => $category->id]);

    $response = $this->actingAs($userA)->getJson('/api/expenses');

    $response->assertStatus(200);
    $this->assertCount(2, $response->json('data.data'));
});

// ----------- GET /api/expenses/{expense} -----------

test('authenticated user can retrieve their own expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->getJson("/api/expenses/{$expense->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $expense->id);
});

test('unauthenticated user cannot retrieve an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->getJson("/api/expenses/{$expense->id}")->assertStatus(401);
});

test('show returns 404 for non-existent expense', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/expenses/99999')
        ->assertStatus(404);
});

test('show returns 403 when user does not own the expense', function () {
    $owner  = User::factory()->create();
    $other  = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->getJson("/api/expenses/{$expense->id}")
        ->assertStatus(403);
});

// ----------- PUT /api/expenses/{expense} -----------

test('authenticated owner can update an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 250.00])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '250.00');
});

test('unauthenticated user cannot update an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->putJson("/api/expenses/{$expense->id}", ['amount' => 250.00])->assertStatus(401);
});

test('update returns 403 when user does not own the expense', function () {
    $owner  = User::factory()->create();
    $other  = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 500])
        ->assertStatus(403);
});

test('update returns 404 for non-existent expense', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/expenses/99999', ['amount' => 500])
        ->assertStatus(404);
});

test('update rejects non-expense category type', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $income   = makeIncomeCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->putJson("/api/expenses/{$expense->id}", ['category_id' => $income->id])
        ->assertStatus(422);
});

// ----------- DELETE /api/expenses/{expense} -----------

test('authenticated owner can delete an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(204);

    $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
});

test('unauthenticated user cannot delete an expense', function () {
    $user     = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->deleteJson("/api/expenses/{$expense->id}")->assertStatus(401);
});

test('delete returns 403 when user does not own the expense', function () {
    $owner  = User::factory()->create();
    $other  = User::factory()->create();
    $category = makeExpenseCategory();
    $expense  = Expense::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(403);
});

test('delete returns 404 for non-existent expense', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/expenses/99999')
        ->assertStatus(404);
});
