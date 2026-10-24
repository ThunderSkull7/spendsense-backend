<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

uses(RefreshDatabase::class);

// ========== POST /api/categories -----------

// (existing test functions from earlier in the file)

// ========== DELETE /api/categories/{category} -----------

test('category with associated budget cannot be deleted', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'name' => 'Test',
        'type' => 'expense',
        'user_id' => $user->id,
        'is_default' => false,
    ]);
    Budget::create([
        'user_id'      => $user->id,
        'limit_amount' => 500,
        'month_year'   => Carbon::now()->toDateString(),
        'category_id'  => $category->id,
    ]);

    // Delete category should be blocked
    $response = $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot delete category: category has associated budgets.']);

    // Budget still exists
    $this->assertDatabaseHas('budgets', [
        'id' => Budget::where('category_id', $category->id)->first()->id,
        'limit_amount' => 500,
    ]);
});

test('blocked deletion preserves all associated records', function () {
    $user = User::factory()->create();
    $category = Category::create([
        'name' => 'Test',
        'type' => 'expense',
        'user_id' => $user->id,
        'is_default' => false,
    ]);
    $budget = Budget::create([
        'user_id'      => $user->id,
        'limit_amount' => 500,
        'month_year'   => Carbon::now()->toDateString(),
        'category_id'  => $category->id,
    ]);
    $expense = Expense::create([
        'user_id'      => $user->id,
        'amount'       => 25,
        'description'  => 'Test expense',
        'expense_date' => Carbon::now()->toDateString(),
        'category_id'  => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}");

    $response->assertStatus(422);

    // All records still exist
    $this->assertDatabaseHas('categories', ['id' => $category->id]);
    $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
});

// ========== ANALYTICS API INTEGRATION TESTS ==========

// (existing test functions from earlier in the file)