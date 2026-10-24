<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Subscription;
use App\Models\User;
use App\Support\DefaultCategories;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ----------- helpers -----------

function categoryPayload(string $name = 'Groceries', string $type = 'expense'): array
{
    return [
        'name' => $name,
        'type' => $type,
    ];
}

function ownedCategory(User $user, string $name = 'Groceries', string $type = 'expense'): Category
{
    return Category::create([
        'name' => $name,
        'type' => $type,
        'user_id' => $user->id,
        'is_default' => false,
    ]);
}

function legacyCategory(string $name = 'Legacy category'): Category
{
    return Category::create([
        'name' => $name,
        'type' => 'expense',
        'user_id' => null,
        'is_default' => false,
    ]);
}

function categoryExpensePayload(int $categoryId, string $date = '2026-10-20'): array
{
    return [
        'amount' => 25.00,
        'category_id' => $categoryId,
        'expense_date' => $date,
        'description' => 'Category ownership test',
    ];
}

// ----------- shared defaults -----------

test('all users receive the same immutable shared defaults', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $responseA = $this->actingAs($userA)->getJson('/api/categories')->assertOk();
    $responseB = $this->actingAs($userB)->getJson('/api/categories')->assertOk();

    $defaultsA = collect($responseA->json('data'))
        ->where('is_default', true)
        ->values();
    $defaultsB = collect($responseB->json('data'))
        ->where('is_default', true)
        ->values();

    expect($defaultsA)->toHaveCount(count(DefaultCategories::all()));
    expect($defaultsB->pluck('id')->all())->toBe($defaultsA->pluck('id')->all());
    expect($defaultsA->every(fn (array $category) => $category['user_id'] === null))->toBeTrue();
    expect($defaultsA->pluck('name')->all())->toContain('Food', 'Salary');
});

test('category ownership preflight becomes a safe no-op after the migration is applied', function () {
    $this->artisan('categories:audit-preflight')
        ->expectsOutputToContain('already applied')
        ->assertSuccessful();
});

test('the category seeder is idempotent and does not touch private categories', function () {
    $user = User::factory()->create();
    $custom = ownedCategory($user, 'Private seeder check');

    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::query()->whereNull('user_id')->where('is_default', true)->count())
        ->toBe(count(DefaultCategories::all()));
    expect(Category::query()->whereNull('user_id')->where('is_default', false)->count())
        ->toBe(0);
    expect($custom->fresh())->not->toBeNull();
});

test('ownership rollback fails closed while any private category exists', function () {
    $user = User::factory()->create();
    ownedCategory($user, 'Private rollback guard');

    $migration = require database_path(
        'migrations/2026_10_24_000001_add_ownership_to_categories_table.php'
    );

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'private categories still exist');

    expect(Category::query()->where('name', 'Private rollback guard')->exists())->toBeTrue();
    expect(Schema::hasColumn('categories', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('categories', 'is_default'))->toBeTrue();
});

test('ownership rollback fails closed when legacy categories share a name', function () {
    legacyCategory('Duplicate rollback name');
    legacyCategory('Duplicate rollback name');

    $migration = require database_path(
        'migrations/2026_10_24_000001_add_ownership_to_categories_table.php'
    );

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Refusing to roll back category ownership');

    expect(Category::query()->where('name', 'Duplicate rollback name')->count())->toBe(2);
    expect(Schema::hasColumn('categories', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('categories', 'is_default'))->toBeTrue();
});

// ----------- create -----------

test('authenticated user can create a private category', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/categories', categoryPayload())
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Groceries')
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.is_default', false);

    expect($response->json('data'))->not->toHaveKey('default_key');

    $this->assertDatabaseHas('categories', [
        'id' => $response->json('data.id'),
        'name' => 'Groceries',
        'type' => 'expense',
        'user_id' => $user->id,
        'is_default' => false,
    ]);
});

test('unauthenticated user cannot create a category', function () {
    $this->postJson('/api/categories', categoryPayload())->assertStatus(401);
});

test('store validates required fields and type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/categories', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type']);

    $this->actingAs($user)
        ->postJson('/api/categories', ['name' => 'Test', 'type' => 'invalid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('custom names are unique per owner and type only', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)
        ->postJson('/api/categories', categoryPayload('Side project'))
        ->assertCreated();

    $this->actingAs($userA)
        ->postJson('/api/categories', categoryPayload('side project'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($userA)
        ->postJson('/api/categories', categoryPayload('Side project', 'income'))
        ->assertCreated();

    $this->actingAs($userB)
        ->postJson('/api/categories', categoryPayload('Side project'))
        ->assertCreated();
});

test('a custom category cannot reuse a shared default name and type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/categories', categoryPayload('Food'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($user)
        ->postJson('/api/categories', categoryPayload('food'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($user)
        ->postJson('/api/categories', categoryPayload('Food', 'income'))
        ->assertCreated();
});

// ----------- list / show -----------

test('index returns shared defaults plus only the authenticated users private categories', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $own = ownedCategory($userA, 'A private');
    $other = ownedCategory($userB, 'B private');
    $legacy = legacyCategory('Legacy hidden');

    $response = $this->actingAs($userA)->getJson('/api/categories')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toHaveCount(count(DefaultCategories::all()) + 1);
    expect($ids)->toContain($own->id);
    expect($ids)->not->toContain($other->id);
    expect($ids)->not->toContain($legacy->id);
});

test('show allows defaults and owned categories but hides other private and legacy categories', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $default = Category::query()->where('name', 'Food')->where('type', 'expense')->firstOrFail();
    $own = ownedCategory($user, 'Owned visible');
    $otherCategory = ownedCategory($other, 'Other hidden');
    $legacy = legacyCategory();

    $this->actingAs($user)->getJson("/api/categories/{$default->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/categories/{$own->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/categories/{$otherCategory->id}")->assertNotFound();
    $this->actingAs($user)->getJson("/api/categories/{$legacy->id}")->assertNotFound();
    $this->actingAs($user)->getJson('/api/categories/99999')->assertNotFound();
});

test('unauthenticated user cannot list or show categories', function () {
    $category = ownedCategory(User::factory()->create());

    $this->getJson('/api/categories')->assertStatus(401);
    $this->getJson("/api/categories/{$category->id}")->assertStatus(401);
});

// ----------- update -----------

test('owner can update a private category', function () {
    $user = User::factory()->create();
    $category = ownedCategory($user, 'Groceries');

    $this->actingAs($user)
        ->putJson("/api/categories/{$category->id}", ['name' => 'Household'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Household')
        ->assertJsonPath('data.is_default', false);
});

test('default categories cannot be edited', function () {
    $user = User::factory()->create();
    $default = Category::query()->where('name', 'Food')->where('type', 'expense')->firstOrFail();

    $this->actingAs($user)
        ->putJson("/api/categories/{$default->id}", ['name' => 'Renamed'])
        ->assertForbidden();

    $this->assertDatabaseHas('categories', [
        'id' => $default->id,
        'name' => 'Food',
        'is_default' => true,
    ]);
});

test('another users private category cannot be edited or even probed', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = ownedCategory($userB, 'B private');

    $this->actingAs($userA)
        ->putJson("/api/categories/{$category->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'B private',
        'user_id' => $userB->id,
    ]);
});

test('update enforces owner-scoped uniqueness and default-name protection', function () {
    $user = User::factory()->create();
    $first = ownedCategory($user, 'Groceries');
    ownedCategory($user, 'Utilities');

    $this->actingAs($user)
        ->putJson("/api/categories/{$first->id}", ['name' => 'Utilities'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($user)
        ->putJson("/api/categories/{$first->id}", ['name' => 'Food'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($user)
        ->putJson("/api/categories/{$first->id}", ['type' => 'invalid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('type changes are allowed only when no financial history references the category', function () {
    $user = User::factory()->create();
    $unused = ownedCategory($user, 'Unused');
    $referenced = ownedCategory($user, 'Referenced');

    Expense::create([
        'user_id' => $user->id,
        'category_id' => $referenced->id,
        'amount' => 10,
        'description' => 'Referenced expense',
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($user)
        ->putJson("/api/categories/{$unused->id}", ['type' => 'income'])
        ->assertOk()
        ->assertJsonPath('data.type', 'income');

    $this->actingAs($user)
        ->putJson("/api/categories/{$referenced->id}", ['type' => 'income'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change category type: category has associated expenses.');
});

test('unauthenticated user cannot update a category', function () {
    $category = ownedCategory(User::factory()->create());

    $this->putJson("/api/categories/{$category->id}", ['name' => 'Nope'])
        ->assertStatus(401);
});

// ----------- delete -----------

test('owner can delete an unreferenced private category', function () {
    $user = User::factory()->create();
    $category = ownedCategory($user, 'Temporary');

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('default categories cannot be deleted', function () {
    $user = User::factory()->create();
    $default = Category::query()->where('name', 'Food')->where('type', 'expense')->firstOrFail();

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$default->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('categories', ['id' => $default->id]);
});

test('another users private category cannot be deleted', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = ownedCategory($userB, 'B private');

    $this->actingAs($userA)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('categories', ['id' => $category->id]);
});

test('delete is blocked while expenses, budgets, schedules or subscriptions reference the category', function () {
    $user = User::factory()->create();
    $cases = [];

    $expenseCategory = ownedCategory($user, 'Expense ref');
    $expense = Expense::create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'amount' => 10,
        'description' => 'Expense ref',
        'expense_date' => '2026-10-20',
    ]);
    $expense->delete();
    $cases[] = [$expenseCategory, 'Cannot delete category: category has associated expenses.'];

    $budgetCategory = ownedCategory($user, 'Budget ref');
    Budget::create([
        'user_id' => $user->id,
        'category_id' => $budgetCategory->id,
        'limit_amount' => 100,
        'month_year' => '2026-10-01',
    ]);
    $cases[] = [$budgetCategory, 'Cannot delete category: category has associated budgets.'];

    $recurringCategory = ownedCategory($user, 'Schedule ref');
    RecurringExpense::create([
        'user_id' => $user->id,
        'description' => 'Schedule ref',
        'amount' => 10,
        'category_id' => $recurringCategory->id,
        'frequency' => 'monthly',
        'start_date' => '2026-10-01',
        'status' => 'active',
    ]);
    $cases[] = [$recurringCategory, 'Cannot delete category: category has associated recurring expense schedules.'];

    $subscriptionCategory = ownedCategory($user, 'Subscription ref');
    Subscription::create([
        'user_id' => $user->id,
        'name' => 'Subscription ref',
        'amount' => 9.99,
        'frequency' => 'monthly',
        'next_renewal_date' => '2026-11-01',
        'category_id' => $subscriptionCategory->id,
        'status' => 'active',
    ]);
    $cases[] = [$subscriptionCategory, 'Cannot delete category: category has associated subscription trackers.'];

    foreach ($cases as [$category, $message]) {
        $this->actingAs($user)
            ->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
});

test('deleting one users category never changes another users category or records', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $categoryA = ownedCategory($userA, 'Shared name');
    $categoryB = ownedCategory($userB, 'Shared name');
    Expense::create([
        'user_id' => $userB->id,
        'category_id' => $categoryB->id,
        'amount' => 50,
        'description' => 'B expense',
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($userA)
        ->deleteJson("/api/categories/{$categoryA->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('categories', ['id' => $categoryA->id]);
    $this->assertDatabaseHas('categories', [
        'id' => $categoryB->id,
        'user_id' => $userB->id,
    ]);
    $this->assertDatabaseHas('expenses', [
        'user_id' => $userB->id,
        'category_id' => $categoryB->id,
    ]);
});

test('unauthenticated user cannot delete a category', function () {
    $category = ownedCategory(User::factory()->create());

    $this->deleteJson("/api/categories/{$category->id}")->assertStatus(401);
});

// ----------- cross-user references ----------

test('expenses reject another users private category', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $categoryB = ownedCategory($userB, 'B expense category');

    $this->actingAs($userA)
        ->postJson('/api/expenses', categoryExpensePayload($categoryB->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->assertDatabaseCount('expenses', 0);
});

test('expenses accept shared defaults and the users own private category', function () {
    $user = User::factory()->create();
    $default = Category::query()->where('name', 'Food')->where('type', 'expense')->firstOrFail();
    $own = ownedCategory($user, 'Own expense category');

    $this->actingAs($user)
        ->postJson('/api/expenses', categoryExpensePayload($default->id))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/expenses', categoryExpensePayload($own->id))
        ->assertCreated();
});

test('expense updates cannot switch to another users private category', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $categoryA = ownedCategory($userA, 'A category');
    $categoryB = ownedCategory($userB, 'B category');
    $expense = Expense::create([
        'user_id' => $userA->id,
        'category_id' => $categoryA->id,
        'amount' => 10,
        'description' => 'A expense',
        'expense_date' => '2026-10-20',
    ]);

    $this->actingAs($userA)
        ->putJson("/api/expenses/{$expense->id}", ['category_id' => $categoryB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    expect($expense->fresh()->category_id)->toBe($categoryA->id);
});

test('budgets, recurring expenses and subscriptions reject another users private category', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $categoryB = ownedCategory($userB, 'B shared target');

    $this->actingAs($userA)
        ->postJson('/api/budgets', [
            'category_id' => $categoryB->id,
            'limit_amount' => 100,
            'month_year' => '2026-10',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->actingAs($userA)
        ->postJson('/api/recurring-expenses', [
            'description' => 'Blocked schedule',
            'amount' => 10,
            'category_id' => $categoryB->id,
            'frequency' => 'monthly',
            'start_date' => '2026-10-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->actingAs($userA)
        ->postJson('/api/subscriptions', [
            'name' => 'Blocked subscription',
            'amount' => 9.99,
            'frequency' => 'monthly',
            'next_renewal_date' => '2026-11-01',
            'category_id' => $categoryB->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

// ----------- legacy rows ----------

test('the historical allow-id exception never exposes another users private category', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherCategory = ownedCategory($other, 'Other private allow check');
    $legacy = legacyCategory('Legacy allow check');

    expect(
        Category::query()
            ->availableTo($user->id, null, $otherCategory->id)
            ->whereKey($otherCategory->id)
            ->exists()
    )->toBeFalse();

    expect(
        Category::query()
            ->availableTo($user->id, null, $legacy->id)
            ->whereKey($legacy->id)
            ->exists()
    )->toBeTrue();
});

test('legacy unowned categories remain visible on history but cannot be selected or managed', function () {

    $user = User::factory()->create();
    $legacy = legacyCategory('Legacy preserved');
    $expense = Expense::create([
        'user_id' => $user->id,
        'category_id' => $legacy->id,
        'amount' => 10,
        'description' => 'Historical expense',
        'expense_date' => '2026-10-20',
    ]);

    $ids = collect($this->actingAs($user)->getJson('/api/categories')->json('data'))->pluck('id');
    expect($ids)->not->toContain($legacy->id);

    $this->actingAs($user)
        ->postJson('/api/expenses', categoryExpensePayload($legacy->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    // An unrelated edit may keep the existing historical category attached.
    $this->actingAs($user)
        ->putJson("/api/expenses/{$expense->id}", [
            'category_id' => $legacy->id,
            'amount' => 12,
        ])
        ->assertOk()
        ->assertJsonPath('data.category_id', $legacy->id);

    $this->actingAs($user)
        ->putJson("/api/categories/{$legacy->id}", ['name' => 'Renamed'])
        ->assertNotFound();

    $this->actingAs($user)
        ->deleteJson("/api/categories/{$legacy->id}")
        ->assertNotFound();
});
