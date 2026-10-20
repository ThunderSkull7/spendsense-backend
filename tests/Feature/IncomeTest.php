<?php

use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----------- helpers -----------

function validIncomePayload(): array
{
    return [
        'amount'      => 150.00,
        'source'      => 'Freelance Work',
        'income_date' => '2025-01-15',
    ];
}

// ----------- POST /api/incomes -----------

test('authenticated user can create an income', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/incomes', validIncomePayload());

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '150.00');

    $this->assertDatabaseHas('incomes', [
        'user_id' => $user->id,
        'amount'  => 150.00,
    ]);
});

test('unauthenticated user cannot create an income', function () {
    $this->postJson('/api/incomes', validIncomePayload())
        ->assertStatus(401);
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'source', 'income_date']);
});

test('store rejects negative amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['amount' => -10]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('store rejects invalid date format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['income_date' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['income_date']);
});

test('store does not trust client-supplied user_id', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $payload = array_merge(validIncomePayload(), ['user_id' => $other->id]);

    $response = $this->actingAs($owner)
        ->postJson('/api/incomes', $payload);

    $response->assertStatus(201);

    $this->assertDatabaseHas('incomes', [
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseMissing('incomes', [
        'user_id' => $other->id,
    ]);
});

// ----------- GET /api/incomes -----------

test('authenticated user can list their incomes paginated', function () {
    $user = User::factory()->create();

    Income::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/incomes');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']]);
});

test('unauthenticated user cannot list incomes', function () {
    $this->getJson('/api/incomes')->assertStatus(401);
});

test('index only returns incomes belonging to authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Income::factory()->count(2)->create(['user_id' => $userA->id]);
    Income::factory()->count(3)->create(['user_id' => $userB->id]);

    $response = $this->actingAs($userA)->getJson('/api/incomes');

    $response->assertStatus(200);
    $this->assertCount(2, $response->json('data.data'));
});

// ----------- GET /api/incomes/{income} -----------

test('authenticated user can retrieve their own income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/incomes/{$income->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $income->id);
});

test('unauthenticated user cannot retrieve an income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->getJson("/api/incomes/{$income->id}")->assertStatus(401);
});

test('show returns 404 for non-existent income', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/incomes/99999')
        ->assertStatus(404);
});

test('show returns 403 when user does not own the income', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->getJson("/api/incomes/{$income->id}")
        ->assertStatus(403);
});

// ----------- PUT /api/incomes/{income} -----------

test('authenticated owner can update an income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/incomes/{$income->id}", ['amount' => 250.00])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.amount', '250.00');
});

test('unauthenticated user cannot update an income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->putJson("/api/incomes/{$income->id}", ['amount' => 250.00])->assertStatus(401);
});

test('update returns 403 when user does not own the income', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->putJson("/api/incomes/{$income->id}", ['amount' => 500])
        ->assertStatus(403);
});

test('update returns 404 for non-existent income', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/incomes/99999', ['amount' => 500])
        ->assertStatus(404);
});

// ----------- DELETE /api/incomes/{income} -----------

test('authenticated owner can delete an income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/incomes/{$income->id}")
        ->assertStatus(204);

    $this->assertSoftDeleted('incomes', ['id' => $income->id]);
});

test('unauthenticated user cannot delete an income', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->deleteJson("/api/incomes/{$income->id}")->assertStatus(401);
});

test('delete returns 403 when user does not own the income', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->deleteJson("/api/incomes/{$income->id}")
        ->assertStatus(403);
});

test('delete returns 404 for non-existent income', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/incomes/99999')
        ->assertStatus(404);
});

// ----------- frequency & notes -----------

test('store persists frequency and notes when provided', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), [
            'frequency' => 'monthly',
            'notes'     => 'October salary',
        ]));

    $response->assertStatus(201)
        ->assertJsonPath('data.frequency', 'monthly')
        ->assertJsonPath('data.notes', 'October salary');

    $this->assertDatabaseHas('incomes', [
        'user_id'   => $user->id,
        'frequency' => 'monthly',
        'notes'     => 'October salary',
    ]);
});

test('store normalizes legacy frequency labels to contract slugs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['frequency' => 'Monthly']))
        ->assertStatus(201)
        ->assertJsonPath('data.frequency', 'monthly');

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['frequency' => 'One-time']))
        ->assertStatus(201)
        ->assertJsonPath('data.frequency', 'one-time');
});

test('store defaults frequency and notes to null when omitted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', validIncomePayload())
        ->assertStatus(201);

    $income = Income::where('user_id', $user->id)->first();
    expect($income->frequency)->toBeNull();
    expect($income->notes)->toBeNull();
});

test('store rejects an unsupported frequency', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['frequency' => 'fortnightly']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency']);
});

test('store rejects notes longer than 1000 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/incomes', array_merge(validIncomePayload(), ['notes' => str_repeat('a', 1001)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['notes']);
});

test('owner can update frequency and notes', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/incomes/{$income->id}", ['frequency' => 'yearly', 'notes' => 'Annual bonus'])
        ->assertStatus(200)
        ->assertJsonPath('data.frequency', 'yearly')
        ->assertJsonPath('data.notes', 'Annual bonus');

    $this->assertDatabaseHas('incomes', [
        'id'        => $income->id,
        'frequency' => 'yearly',
        'notes'     => 'Annual bonus',
    ]);
});

test('update rejects an unsupported frequency', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->putJson("/api/incomes/{$income->id}", ['frequency' => 'sometimes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['frequency']);
});

test('owner can clear frequency and notes by sending null', function () {
    $user = User::factory()->create();
    $income = Income::factory()->create([
        'user_id'   => $user->id,
        'frequency' => 'monthly',
        'notes'     => 'temporary note',
    ]);

    $this->actingAs($user)
        ->putJson("/api/incomes/{$income->id}", ['frequency' => null, 'notes' => null])
        ->assertStatus(200);

    $income->refresh();
    expect($income->frequency)->toBeNull();
    expect($income->notes)->toBeNull();
});
