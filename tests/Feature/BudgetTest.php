<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----------- helpers -----------

function validBudgetPayload(int $categoryId): array
{
    return [
        'category_id'  => $categoryId,
        'limit_amount' => 500.00,
        'month_year'   => '2025-01-15',
    ];
}

// ----------- POST /api/budgets -----------

test('authenticated user can create a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $response = $this->actingAs($user)
        ->postJson('/api/budgets', validBudgetPayload($category->id));

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.limit_amount', '500.00');

    $this->assertDatabaseHas('budgets', [
        'user_id'     => $user->id,
        'category_id' => $category->id,
    ]);
});

test('unauthenticated user cannot create a budget', function () {
    $category = makeExpenseCategory();

    $this->postJson('/api/budgets', validBudgetPayload($category->id))
        ->assertStatus(401);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/budgets', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id', 'limit_amount', 'month_year']);
});

test('store rejects negative limit amount', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)
        ->postJson('/api/budgets', array_merge(validBudgetPayload($category->id), ['limit_amount' => -100]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['limit_amount']);
});

test('store rejects invalid date format', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    $this->actingAs($user)
        ->postJson('/api/budgets', array_merge(validBudgetPayload($category->id), ['month_year' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['month_year']);
});

test('store rejects non-existent category_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/budgets', validBudgetPayload(9999))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('store does not trust client-supplied user_id', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();

    $payload = array_merge(validBudgetPayload($category->id), ['user_id' => $other->id]);

    $response = $this->actingAs($owner)
        ->postJson('/api/budgets', $payload);

    $response->assertStatus(201);

    $this->assertDatabaseHas('budgets', [
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseMissing('budgets', [
        'user_id' => $other->id,
    ]);
});

// ----------- GET /api/budgets -----------

test('authenticated user can list their budgets paginated', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();

    Budget::factory()->count(3)->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/budgets');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']]);
});

test('unauthenticated user cannot list budgets', function () {
    $this->getJson('/api/budgets')->assertStatus(401);
});

test('index only returns budgets belonging to authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = makeExpenseCategory();

    Budget::factory()->count(2)->create(['user_id' => $userA->id, 'category_id' => $category->id]);
    Budget::factory()->count(3)->create(['user_id' => $userB->id, 'category_id' => $category->id]);

    $response = $this->actingAs($userA)->getJson('/api/budgets');

    $response->assertStatus(200);
    $this->assertCount(2, $response->json('data.data'));
});

// ----------- GET /api/budgets/{budget} -----------

test('authenticated user can retrieve their own budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->getJson("/api/budgets/{$budget->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $budget->id);
});

test('unauthenticated user cannot retrieve a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->getJson("/api/budgets/{$budget->id}")->assertStatus(401);
});

test('show returns 404 for non-existent budget', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/budgets/99999')
        ->assertStatus(404);
});

test('show returns 403 when user does not own the budget', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->getJson("/api/budgets/{$budget->id}")
        ->assertStatus(403);
});

// ----------- PUT /api/budgets/{budget} -----------

test('authenticated owner can update a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->putJson("/api/budgets/{$budget->id}", ['limit_amount' => 750.00])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.limit_amount', '750.00');
});

test('unauthenticated user cannot update a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->putJson("/api/budgets/{$budget->id}", ['limit_amount' => 750.00])->assertStatus(401);
});

test('update returns 403 when user does not own the budget', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->putJson("/api/budgets/{$budget->id}", ['limit_amount' => 500])
        ->assertStatus(403);
});

test('update returns 404 for non-existent budget', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/budgets/99999', ['limit_amount' => 500])
        ->assertStatus(404);
});

// ----------- DELETE /api/budgets/{budget} -----------

test('authenticated owner can delete a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->actingAs($user)
        ->deleteJson("/api/budgets/{$budget->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
});

test('unauthenticated user cannot delete a budget', function () {
    $user = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);

    $this->deleteJson("/api/budgets/{$budget->id}")->assertStatus(401);
});

test('delete returns 403 when user does not own the budget', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $category = makeExpenseCategory();
    $budget = Budget::factory()->create(['user_id' => $owner->id, 'category_id' => $category->id]);

    $this->actingAs($other)
        ->deleteJson("/api/budgets/{$budget->id}")
        ->assertStatus(403);
});

test('delete returns 404 for non-existent budget', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/budgets/99999')
        ->assertStatus(404);
});
