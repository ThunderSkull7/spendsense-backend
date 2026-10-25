<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot view or update a profile', function () {
    $this->getJson('/api/user')
        ->assertStatus(401)
        ->assertJsonStructure(['message']);

    $this->putJson('/api/user', ['name' => 'Guest User'])
        ->assertStatus(401);
});

test('an authenticated user can view their profile without sensitive fields', function () {
    $user = User::factory()->create([
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
    ]);

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'Asha Rao')
        ->assertJsonPath('user.email', 'asha@example.com')
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');
});

test('an authenticated user can update their display name', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($user)
        ->putJson('/api/user', ['name' => '  New Name  '])
        ->assertOk()
        ->assertJsonPath('user.name', 'New Name')
        ->assertJsonPath('message', 'Profile updated.');

    expect($user->fresh()->name)->toBe('New Name');
});

test('updating a profile cannot change the sign-in email or another user', function () {
    $user = User::factory()->create([
        'name' => 'Signed In User',
        'email' => 'signed-in@example.com',
    ]);
    $other = User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
    ]);

    $this->actingAs($user)
        ->putJson('/api/user', [
            'name' => 'Updated Name',
            'email' => 'attacker@example.com',
            'id' => $other->id,
            'user_id' => $other->id,
        ])
        ->assertOk()
        ->assertJsonPath('user.name', 'Updated Name')
        ->assertJsonPath('user.email', 'signed-in@example.com');

    expect($user->fresh()->name)->toBe('Updated Name');
    expect($user->fresh()->email)->toBe('signed-in@example.com');
    expect($other->fresh()->name)->toBe('Other User');
    expect($other->fresh()->email)->toBe('other@example.com');
});

test('profile updates validate the display name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)
        ->putJson('/api/user', ['name' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->actingAs($user)
        ->putJson('/api/user', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});
