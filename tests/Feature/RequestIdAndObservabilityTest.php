<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('requests receive an X-Request-Id header in responses', function () {
    $response = $this->getJson('/sanctum/csrf-cookie');

    $header = $response->headers->get('X-Request-Id');
    expect($header)->not->toBeNull();
    expect($header)->toStartWith('req_');
});

test('incoming client X-Request-Id header is preserved and echoed', function () {
    $customId = 'req_client_trace_12345';
    $response = $this->withHeaders([
        'X-Request-Id' => $customId,
    ])->getJson('/sanctum/csrf-cookie');

    expect($response->headers->get('X-Request-Id'))->toBe($customId);
});

test('failed authentication responses contain X-Request-Id for privacy safe correlation', function () {
    $response = $this->postJson('/api/login', [
        'email'    => 'unknown@example.com',
        'password' => 'wrong',
    ]);

    expect($response->status())->toBe(401);
    expect($response->headers->get('X-Request-Id'))->not->toBeNull();
});

