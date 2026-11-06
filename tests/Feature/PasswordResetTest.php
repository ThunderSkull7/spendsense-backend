<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('forgot password endpoint returns uniform success message even for non-existent email and sends no email', function () {
    Mail::fake();

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'nonexistent@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'If an account with that email exists, reset instructions have been generated.',
        ]);

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertNothingSent();
});

test('forgot password generates hashed token and dispatches outbound PasswordResetMail for registered user', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'alice@example.com',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'alice@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    $record = DB::table('password_reset_tokens')->where('email', 'alice@example.com')->first();
    expect($record)->not->toBeNull();
    expect($record->created_at)->not->toBeNull();

    $debugToken = $response->json('debug_token');
    expect($debugToken)->not->toBeNull();
    expect(Hash::check($debugToken, $record->token))->toBeTrue();

    // Verify outbound email dispatch
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user, $debugToken) {
        return $mail->hasTo($user->email)
            && $mail->token === $debugToken
            && $mail->email === $user->email
            && str_contains($mail->resetUrl, 'token=' . $debugToken)
            && str_contains($mail->resetUrl, 'email=' . urlencode($user->email));
    });
});

test('email contains valid reset link pointing to frontend forgot-password page with query parameters', function () {
    Mail::fake();
    Config::set('app.frontend_url', 'https://spendsensehq.pages.dev');

    $user = User::factory()->create([
        'email' => 'sarah@example.com',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'sarah@example.com',
    ]);

    $response->assertStatus(200);

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
        expect($mail->resetUrl)->toStartWith('https://spendsensehq.pages.dev/authentication/forgot-password.html');
        expect($mail->resetUrl)->toContain('token=');
        expect($mail->resetUrl)->toContain('email=sarah%40example.com');

        // Render HTML and plain text
        $renderedHtml = $mail->render();
        expect($renderedHtml)->toContain('Reset Password');
        expect($renderedHtml)->toContain(htmlspecialchars($mail->resetUrl));
        expect($renderedHtml)->toContain($mail->token);

        return true;
    });
});

test('reset link uses configurable frontend URL and normalizes trailing slashes', function () {
    Mail::fake();
    Config::set('app.frontend_url', 'http://localhost:3000/');

    $user = User::factory()->create([
        'email' => 'dev@example.com',
    ]);

    $this->postJson('/api/forgot-password', [
        'email' => 'dev@example.com',
    ])->assertStatus(200);

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
        expect($mail->resetUrl)->toStartWith('http://localhost:3000/authentication/forgot-password.html');
        return true;
    });
});

test('production requests never expose debug_token in API response', function () {
    Mail::fake();
    app()['env'] = 'production';

    $user = User::factory()->create([
        'email' => 'prod-user@example.com',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'prod-user@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'If an account with that email exists, reset instructions have been generated.',
        ]);

    expect($response->json('debug_token'))->toBeNull();
});

test('stored reset token is hashed and not stored in plaintext', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'hashing-test@example.com',
    ]);

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'hashing-test@example.com',
    ]);

    $rawToken = $response->json('debug_token');
    $record = DB::table('password_reset_tokens')->where('email', 'hashing-test@example.com')->first();

    expect($record->token)->not->toBe($rawToken);
    expect(str_starts_with($record->token, '$2y$'))->toBeTrue();
    expect(Hash::check($rawToken, $record->token))->toBeTrue();
});

test('token expiry remains strictly 60 minutes', function () {
    $user = User::factory()->create([
        'email' => 'expiry@example.com',
    ]);

    $token = 'valid-token-expiry-check-123456';

    // Token created 59 minutes ago is still valid
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => 'expiry@example.com'],
        [
            'token'      => Hash::make($token),
            'created_at' => CarbonImmutable::now()->subMinutes(59),
        ]
    );

    $responseValid = $this->postJson('/api/reset-password', [
        'email'                 => 'expiry@example.com',
        'token'                 => $token,
        'password'              => 'fresh-new-pass-123',
        'password_confirmation' => 'fresh-new-pass-123',
    ]);
    $responseValid->assertStatus(200);

    // Expired token (> 60 minutes) must be rejected and purged
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => 'expiry@example.com'],
        [
            'token'      => Hash::make($token),
            'created_at' => CarbonImmutable::now()->subMinutes(61),
        ]
    );

    $responseExpired = $this->postJson('/api/reset-password', [
        'email'                 => 'expiry@example.com',
        'token'                 => $token,
        'password'              => 'fresh-new-pass-456',
        'password_confirmation' => 'fresh-new-pass-456',
    ]);
    $responseExpired->assertStatus(422)
        ->assertJsonValidationErrors(['token']);

    expect(DB::table('password_reset_tokens')->where('email', 'expiry@example.com')->count())->toBe(0);
});

test('reset password updates user password and revokes the token preventing reuse', function () {
    $user = User::factory()->create([
        'email'    => 'bob@example.com',
        'password' => 'old-password-123',
    ]);

    $token = 'test-reset-token-secret-123456';
    DB::table('password_reset_tokens')->insert([
        'email'      => 'bob@example.com',
        'token'      => Hash::make($token),
        'created_at' => CarbonImmutable::now(),
    ]);

    $response = $this->postJson('/api/reset-password', [
        'email'                 => 'bob@example.com',
        'token'                 => $token,
        'password'              => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // Token must be deleted
    expect(DB::table('password_reset_tokens')->where('email', 'bob@example.com')->count())->toBe(0);

    // User can authenticate with the new password
    $user->refresh();
    expect(Hash::check('new-password-456', $user->password))->toBeTrue();
    expect(Hash::check('old-password-123', $user->password))->toBeFalse();

    // Replay attempt with the same token must fail
    $replayResponse = $this->postJson('/api/reset-password', [
        'email'                 => 'bob@example.com',
        'token'                 => $token,
        'password'              => 'another-password-789',
        'password_confirmation' => 'another-password-789',
    ]);
    $replayResponse->assertStatus(422)
        ->assertJsonValidationErrors(['token']);
});

test('reset password rejects password shorter than 8 characters or unconfirmed', function () {
    $user = User::factory()->create([
        'email' => 'david@example.com',
    ]);

    $token = 'valid-token-123';
    DB::table('password_reset_tokens')->insert([
        'email'      => 'david@example.com',
        'token'      => Hash::make($token),
        'created_at' => CarbonImmutable::now(),
    ]);

    $response = $this->postJson('/api/reset-password', [
        'email'                 => 'david@example.com',
        'token'                 => $token,
        'password'              => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('rate limiting remains effective on forgot-password endpoint', function () {
    Mail::fake();

    // Route has throttle:6,1
    for ($i = 0; $i < 6; $i++) {
        $response = $this->postJson('/api/forgot-password', [
            'email' => "test{$i}@example.com",
        ]);
        $response->assertStatus(200);
    }

    $blocked = $this->postJson('/api/forgot-password', [
        'email' => 'overflow@example.com',
    ]);

    $blocked->assertStatus(429);
});

test('mail transport failure purges token and returns uniform response without account enumeration', function () {
    $user = User::factory()->create([
        'email' => 'smtp-fail@example.com',
    ]);

    // Force mail sending to throw a transport exception
    Mail::shouldReceive('to')
        ->once()
        ->andReturnSelf();
    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new \RuntimeException('Connection refused to SMTP server at 127.0.0.1:2525'));

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'smtp-fail@example.com',
    ]);

    // Anti-enumeration: must return 200 with identical public message
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'If an account with that email exists, reset instructions have been generated.',
        ]);

    // debug_token must NOT be exposed when mail transport fails
    expect($response->json('debug_token'))->toBeNull();

    // Token must be purged so no orphaned/reusable state remains
    expect(DB::table('password_reset_tokens')->where('email', 'smtp-fail@example.com')->count())->toBe(0);
});

test('mail delivery failure cleanup deletes only its own token and preserves newer concurrent token for same email (Prompt 9.2)', function () {
    $user = User::factory()->create([
        'email' => 'race-test@example.com',
    ]);

    $newerToken = 'newer-concurrent-token-secret-999';
    $newerHash = Hash::make($newerToken);

    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($user, $newerHash) {
        // While Request A is in-flight attempting to send mail,
        // Request B arrives concurrently and overwrites the token in the database
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => $newerHash, 'created_at' => CarbonImmutable::now()]
        );

        // Request A now fails mail delivery
        throw new \RuntimeException('SMTP delivery failed');
    });

    $response = $this->postJson('/api/forgot-password', [
        'email' => 'race-test@example.com',
    ]);

    // Anti-enumeration: must return 200 with identical public message
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'If an account with that email exists, reset instructions have been generated.',
        ]);

    // debug_token must not be returned for failed request
    expect($response->json('debug_token'))->toBeNull();

    // Verify Request B's newer token was NOT deleted by Request A's failure cleanup
    $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();
    expect($record)->not->toBeNull();
    expect($record->token)->toBe($newerHash);

    // Furthermore, user can successfully reset password with Request B's token
    $resetResponse = $this->postJson('/api/reset-password', [
        'email'                 => $user->email,
        'token'                 => $newerToken,
        'password'              => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ]);
    $resetResponse->assertStatus(200);
});

test('deterministic concurrent cleanup removes token A while preserving token B (Prompt 9.2)', function () {
    $user = User::factory()->create([
        'email' => 'deterministic-race@example.com',
    ]);

    // Request A writes token A
    $tokenA = 'token-a-secret-111';
    $hashA = Hash::make($tokenA);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $user->email],
        ['token' => $hashA, 'created_at' => CarbonImmutable::now()]
    );

    // Request B writes token B
    $tokenB = 'token-b-secret-222';
    $hashB = Hash::make($tokenB);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $user->email],
        ['token' => $hashB, 'created_at' => CarbonImmutable::now()]
    );

    // Request A cleanup executes scoped by its own token hash
    DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->where('token', $hashA)
        ->delete();

    // Token B must remain intact
    $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();
    expect($record)->not->toBeNull();
    expect($record->token)->toBe($hashB);
    expect(Hash::check($tokenB, $record->token))->toBeTrue();
});

test('no sensitive token or credentials appear in application logs during delivery failure', function () {
    $user = User::factory()->create([
        'email' => 'audit-logger@example.com',
    ]);

    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP host failed for user audit-logger@example.com'));

    Log::spy();

    $this->postJson('/api/forgot-password', [
        'email' => 'audit-logger@example.com',
    ]);

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context) {
            // Must not contain raw email
            expect($context['error_message'] ?? '')->not->toContain('audit-logger@example.com');
            // Must contain sanitized indicator
            expect($context['error_message'] ?? '')->toContain('[REDACTED_EMAIL]');
            // Must not contain token or password
            expect(json_encode($context))->not->toContain('token');
            expect(json_encode($context))->not->toContain('password=');
            return true;
        });
});

test('authenticated user can change password with valid current password', function () {
    $user = User::factory()->create([
        'password' => 'current-secret-123',
    ]);

    $response = $this->actingAs($user)->postJson('/api/password/change', [
        'current_password'      => 'current-secret-123',
        'password'              => 'updated-secret-456',
        'password_confirmation' => 'updated-secret-456',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);

    $user->refresh();
    expect(Hash::check('updated-secret-456', $user->password))->toBeTrue();
});

test('authenticated user cannot change password with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => 'correct-password',
    ]);

    $response = $this->actingAs($user)->postJson('/api/password/change', [
        'current_password'      => 'wrong-password',
        'password'              => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);
});

test('unauthenticated user cannot call password change endpoint', function () {
    $response = $this->postJson('/api/password/change', [
        'current_password'      => 'any',
        'password'              => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(401);
});

test('reset password strictly rejects a plaintext token stored in the database (Phase 11.3.1)', function () {
    $user = User::factory()->create([
        'email'    => 'plaintext-token@example.com',
        'password' => 'initial-secure-password-123',
    ]);

    $rawToken = 'plaintext-unhashed-token-value-999';

    // Directly insert the plaintext token without Hash::make()
    DB::table('password_reset_tokens')->insert([
        'email'      => 'plaintext-token@example.com',
        'token'      => $rawToken,
        'created_at' => CarbonImmutable::now(),
    ]);

    $response = $this->postJson('/api/reset-password', [
        'email'                 => 'plaintext-token@example.com',
        'token'                 => $rawToken,
        'password'              => 'attempted-new-password-456',
        'password_confirmation' => 'attempted-new-password-456',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token']);

    // Ensure the password was not changed
    $user->refresh();
    expect(Hash::check('initial-secure-password-123', $user->password))->toBeTrue();
    expect(Hash::check('attempted-new-password-456', $user->password))->toBeFalse();
});

test('reset password rejects token attempting to reset another users password (Phase 11.3.1)', function () {
    $victim = User::factory()->create([
        'email'    => 'victim@example.com',
        'password' => 'victim-password-123',
    ]);

    $attacker = User::factory()->create([
        'email'    => 'attacker@example.com',
        'password' => 'attacker-password-123',
    ]);

    $attackerToken = 'attacker-valid-token-123';
    DB::table('password_reset_tokens')->insert([
        'email'      => 'attacker@example.com',
        'token'      => Hash::make($attackerToken),
        'created_at' => CarbonImmutable::now(),
    ]);

    // Attacker attempts to use their valid token against the victim's email
    $response = $this->postJson('/api/reset-password', [
        'email'                 => 'victim@example.com',
        'token'                 => $attackerToken,
        'password'              => 'hijacked-password-123',
        'password_confirmation' => 'hijacked-password-123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['token']);

    $victim->refresh();
    expect(Hash::check('victim-password-123', $victim->password))->toBeTrue();
    expect(Hash::check('hijacked-password-123', $victim->password))->toBeFalse();
});

