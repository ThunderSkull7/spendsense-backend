<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear(md5('login'));
    // Clear in-memory cache to ensure clean rate-limit state between tests
    cache()->flush();
});

test('account plus IP is throttled after 5 failed attempts', function () {
    $email = 'bruteforce@example.com';
    $ip = '192.168.1.10';

    for ($i = 1; $i <= 5; $i++) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong-pass-'.$i,
            ]);
        $response->assertStatus(401);
    }

    // 6th attempt from the same IP on the same account must be throttled
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong-pass-6',
        ]);

    $throttled->assertStatus(429)
        ->assertJsonStructure(['message']);
});

test('account level throttling blocks distributed brute force across multiple IPs after 10 attempts', function () {
    $email = 'distributed-target@example.com';

    // Simulate 10 different attacking IPs targeting the same account
    for ($i = 1; $i <= 10; $i++) {
        $ip = "10.0.0.{$i}";
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong-pass-'.$i,
            ])
            ->assertStatus(401);
    }

    // 11th attempt from a fresh IP (IP 11) must be throttled by the account-level limiter
    $freshIp = '10.0.0.11';
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $freshIp])
        ->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong-pass-11',
        ]);

    $throttled->assertStatus(429);
});

test('IP level abuse protection blocks an IP after 30 total attempts across different accounts', function () {
    $ip = '172.16.0.50';

    // Attacker sprays 30 different usernames from one IP (1 attempt each)
    for ($i = 1; $i <= 30; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => "sprayed-user-{$i}@example.com",
                'password' => 'password123',
            ])
            ->assertStatus(401);
    }

    // 31st attempt on a new username from the same IP must be throttled
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => 'sprayed-user-31@example.com',
            'password' => 'password123',
        ]);

    $throttled->assertStatus(429);
});

test('multiple legitimate users on the same IP can log in successfully without being blocked at 6 attempts', function () {
    $sharedIp = '198.51.100.1';

    // Create 10 distinct users
    $users = User::factory()->count(10)->create([
        'password' => 'correct-password',
    ]);

    // All 10 users log in from the same shared NAT/office IP
    foreach ($users as $index => $user) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
});

test('successful login clears the account and account-plus-ip rate limit counters', function () {
    $ip = '192.168.1.75';
    $user = User::factory()->create([
        'email' => 'retry-user@example.com',
        'password' => 'valid-pass-123',
    ]);

    // User makes 4 failed login attempts (1 attempt left before account+IP 5 threshold)
    for ($i = 1; $i <= 4; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-pass',
            ])
            ->assertStatus(401);
    }

    // 5th attempt succeeds with valid password
    $success = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'valid-pass-123',
        ]);
    $success->assertOk();

    // 6th attempt (even if wrong) is NOT throttled because the counter was reset on success
    $postReset = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-pass-after',
        ]);
    $postReset->assertStatus(401); // Not 429!
});

test('successful login does not clear the IP-level abuse counter', function () {
    $ip = '192.168.1.99';
    $legitUser = User::factory()->create([
        'email' => 'legit@example.com',
        'password' => 'valid-pass-123',
    ]);

    // 29 failed attempts on other accounts from this IP
    for ($i = 1; $i <= 29; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => "target-{$i}@example.com",
                'password' => 'wrong',
            ])
            ->assertStatus(401);
    }

    // 30th attempt succeeds for legit user (hits the 30-attempt IP limit)
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => $legitUser->email,
            'password' => 'valid-pass-123',
        ])
        ->assertOk();

    // 31st attempt from this IP must STILL be throttled because IP abuse counter is not wiped
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => $legitUser->email,
            'password' => 'valid-pass-123',
        ]);
    $throttled->assertStatus(429);
});

test('missing or malformed email values cannot bypass protection', function () {
    $ip = '10.20.30.40';

    // 5 attempts with missing email from the same IP
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'password' => 'some-pass',
            ]);
        $response->assertStatus(422); // Validation error
    }

    // 6th attempt with missing email must be throttled with 429
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'password' => 'some-pass',
        ]);
    $throttled->assertStatus(429);
});

test('email normalization prevents casing bypass of the rate limiter', function () {
    $ip = '10.99.88.77';

    // 5 attempts with varying casing and whitespace
    $variants = [
        'CASE-USER@example.com',
        'case-user@example.com',
        'Case-User@Example.Com',
        '  case-user@example.com  ',
        'CASE-USER@EXAMPLE.COM',
    ];

    foreach ($variants as $variant) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $variant,
                'password' => 'wrong-pass',
            ])
            ->assertStatus(401);
    }

    // 6th attempt must be throttled
    $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', [
            'email' => 'case-user@example.com',
            'password' => 'wrong-pass-6',
        ]);
    $throttled->assertStatus(429);
});
