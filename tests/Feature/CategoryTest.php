<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----------- helpers -----------

function validCategoryPayload(): array
{
    return [
        'name' => 'Groceries',
        'type' => 'expense',
    ];
};

// ----------- POST /api/categories -----------

test('authenticated user can create a category', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/categories', validCategoryPayload());

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.type', 'expense');

    $this->assertDatabaseHas('categories', [
        'name' => 'Groceries',
        'type' => 'expense',
    ]);
});

test('unauthenticated user cannot create a category', function () {
    $this->postJson('/api/categories', validCategoryPayload())
        ->assertStatus(401);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/categories', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type']);
});

test('store validates type enum', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Test', 'type' => 'invalid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('store enforces unique name constraint', function () {
    $user = User::factory()->create();
    Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->actingAs($user)
        ->postJson('/api/categories', validCategoryPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('store accepts income type', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Salary', 'type' => 'income']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'income');
});

// ----------- GET /api/categories -----------

test('authenticated user can list all categories', function () {
    $user = User::factory()->create();

    Category::create(['name' => 'Groceries', 'type' => 'expense']);
    Category::create(['name' => 'Salary', 'type' => 'income']);

    $response = $this->actingAs($user)
        ->getJson('/api/categories');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);
    $this->assertCount(2, $response->json('data'));
});

test('unauthenticated user cannot list categories', function () {
    $this->getJson('/api/categories')->assertStatus(401);
});

test('index returns all categories regardless of user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Category::create(['name' => 'Groceries', 'type' => 'expense']);
    Category::create(['name' => 'Salary', 'type' => 'income']);

    $responseA = $this->actingAs($userA)->getJson('/api/categories');
    $responseB = $this->actingAs($userB)->getJson('/api/categories');

    $this->assertCount(2, $responseA->json('data'));
    $this->assertCount(2, $responseB->json('data'));
});

// ----------- GET /api/categories/{category} -----------

test('authenticated user can retrieve a category', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->actingAs($user)
        ->getJson("/api/categories/{$category->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $category->id);
});

test('unauthenticated user cannot retrieve a category', function () {
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->getJson("/api/categories/{$category->id}")->assertStatus(401);
});

test('show returns 404 for non-existent category', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/categories/99999')
        ->assertStatus(404);
});

// ----------- PUT /api/categories/{category} -----------

test('authenticated user can update a category', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", ['name' => 'Food'])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Food');
});

test('unauthenticated user cannot update a category', function () {
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->putJson("/api/categories/{$category->id}", ['name' => 'Food'])->assertStatus(401);
});

test('update returns 404 for non-existent category', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/categories/99999', ['name' => 'Food'])
        ->assertStatus(404);
});

test('update enforces unique name constraint', function () {
    $user = User::factory()->create();
    $cat1 = Category::create(['name' => 'Groceries', 'type' => 'expense']);
    Category::create(['name' => 'Utilities', 'type' => 'expense']);

    $this->actingAs($user)
        ->putJson("/api/categories/{$cat1->id}", ['name' => 'Utilities'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('update validates type enum', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", ['type' => 'invalid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

// ----------- DELETE /api/categories/{category} -----------

test('authenticated user can delete a category with no dependents', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertStatus(204);

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('unauthenticated user cannot delete a category', function () {
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);

    $this->deleteJson("/api/categories/{$category->id}")->assertStatus(401);
});

test('delete returns 404 for non-existent category', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/categories/99999')
        ->assertStatus(404);
});

test('category with associated expense cannot be deleted', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);
    \App\Models\Expense::create([
        'user_id' => $user->id,
        'amount' => 10.50,
        'description' => 'Test expense',
        'expense_date' => now()->toDateString(),
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete category: category has associated expenses.']);

    // Category still exists; expense preserved
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('category with associated budget cannot be deleted', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);
    \App\Models\Budget::create([
        'user_id' => $user->id,
        'limit_amount' => 100.00,
        'month_year' => now()->format('Y-m'),
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete category: category has associated budgets.']);

    // Category still exists; budget preserved
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('blocked deletion preserves all associated records', function () {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Groceries', 'type' => 'expense']);
    $expense = \App\Models\Expense::create([
        'user_id' => $user->id,
        'amount' => 25.00,
        'description' => 'Important expense',
        'expense_date' => now()->toDateString(),
        'category_id' => $category->id,
    ]);
    $budget = \App\Models\Budget::create([
        'user_id' => $user->id,
        'limit_amount' => 200.00,
        'month_year' => now()->format('Y-m'),
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422);

    // All records still exist
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
    $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
});

test('records belonging to one user are intact when another user attempts category deletion', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $category = Category::create(['name' => 'Bills', 'type' => 'expense']);

    // User A has an expense with this category
    \App\Models\Expense::create([
        'user_id' => $userA->id,
        'amount' => 50.00,
        'description' => 'User A expense',
        'expense_date' => now()->toDateString(),
        'category_id' => $category->id,
    ]);

    // User B attempts to delete the category
    $response = $this->actingAs($userB)
        ->deleteJson("/api/categories/{$category->id}");

    // Deletion should be blocked
    $response->assertStatus(422);

    // User A's expense is still intact
    $this->assertDatabaseHas('expenses', [
        'user_id' => $userA->id,
        'category_id' => $category->id,
        'description' => 'User A expense',
    ]);
});

test('category with no dependents can be deleted by one user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $category = Category::create(['name' => 'Travel', 'type' => 'expense']);

    // Neither user has expenses or budgets with this category
    $responseA = $this->actingAs($userA)
        ->deleteJson("/api/categories/{$category->id}");

    // User A's delete should succeed (204)
    $responseA->assertStatus(204);

    // Category is deleted from the database
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);

    // User B's subsequent delete should return 404 (category no longer exists)
    $responseB = $this->actingAs($userB)
        ->deleteJson("/api/categories/{$category->id}");

    // Category no longer exists; 404 is expected
    $responseB->assertStatus(404);
});