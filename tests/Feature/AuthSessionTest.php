<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Real (stateful) authentication tests
|--------------------------------------------------------------------------
|
| These tests exercise Sanctum's cookie-based SPA authentication. Requests
| carry an Origin/Referer header matching a stateful domain so that Laravel
| starts a session and applies CSRF protection, exactly as a browser would.
|
| The test client does not persist cookies automatically, so the session
| cookie is captured from a response and replayed on the next request. Between
| requests the in-process auth guards and session store are reset so that
| authentication must be rehydrated from the session cookie alone (otherwise
| shared singleton state would mask real behaviour).
|
*/

function ssStatefulHeaders(): array
{
    return [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/',
    ];
}

function ssSessionCookieName(): string
{
    return config('session.cookie');
}

function ssSessionId($response): ?string
{
    return $response->getCookie(ssSessionCookieName())?->getValue();
}

/**
 * Simulate a fresh request: drop cached auth guards and clear the shared
 * in-memory session store so the next request can only authenticate via the
 * session cookie it is sent.
 */
function ssResetRequestState(): void
{
    app('auth')->forgetGuards();
    app('session')->driver()->flush();
}

test('csrf-cookie endpoint issues an XSRF-TOKEN cookie', function () {
    // Canonical Sanctum route (web middleware - always stateful).
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');

    // App route variant, reached as a stateful SPA request.
    $this->withHeaders(ssStatefulHeaders())
        ->get('/api/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

test('registration establishes a session cookie', function () {
    $response = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'Register User',
        'email' => 'register@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()->assertCookie(ssSessionCookieName());

    expect(User::where('email', 'register@example.com')->exists())->toBeTrue();
    expect(ssSessionId($response))->not->toBeNull();
});

test('login establishes a session cookie', function () {
    $user = User::factory()->create(); // factory password is "password"

    $response = $this->withHeaders(ssStatefulHeaders())->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()->assertCookie(ssSessionCookieName());
    expect(ssSessionId($response))->not->toBeNull();
});

test('invalid credentials return 401', function () {
    $user = User::factory()->create();

    $this->withHeaders(ssStatefulHeaders())->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

test('an authenticated session persists across separate requests', function () {
    $register = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'Persist User',
        'email' => 'persist@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $register->assertCreated();

    $sessionId = ssSessionId($register);
    expect($sessionId)->not->toBeNull();

    // Fresh request: only the replayed session cookie can authenticate.
    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/api/expenses')
        ->assertOk();

    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/user')
        ->assertOk()
        ->assertJsonPath('email', 'persist@example.com');
});

test('profile hydration and updates work through a real stateful session', function () {
    $register = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'Profile User',
        'email' => 'profile-session@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $register->assertCreated();
    $sessionId = ssSessionId($register);
    expect($sessionId)->not->toBeNull();

    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.name', 'Profile User')
        ->assertJsonPath('user.email', 'profile-session@example.com');

    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->putJson('/api/user', ['name' => 'Updated Profile User'])
        ->assertOk()
        ->assertJsonPath('user.name', 'Updated Profile User');

    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.name', 'Updated Profile User');
});

test('logout invalidates the authenticated session', function () {
    $register = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'Logout User',
        'email' => 'logout@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $register->assertCreated();
    $sessionId = ssSessionId($register);

    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/api/expenses')
        ->assertOk();

    // Logout (CSRF is bypassed inside the testing environment).
    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->postJson('/api/logout')
        ->assertOk();

    // The same session cookie can no longer access protected endpoints.
    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $sessionId)
        ->getJson('/api/expenses')
        ->assertStatus(401);
});

test('unauthenticated requests return JSON 401 without an Accept header', function () {
    // No Accept: application/json header: must still be JSON (not a redirect/500).
    $this->get('/api/expenses')
        ->assertStatus(401)
        ->assertJsonStructure(['message']);

    $this->get('/user')
        ->assertStatus(401)
        ->assertJsonStructure(['message']);
});

test('a second user cannot access the first users records', function () {
    // Two independent registrations (no cookie carried into the register calls).
    $aRegister = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'User A',
        'email' => 'usera@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $aRegister->assertCreated();
    $aSession = ssSessionId($aRegister);

    ssResetRequestState();

    $bRegister = $this->withHeaders(ssStatefulHeaders())->postJson('/api/register', [
        'name' => 'User B',
        'email' => 'userb@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $bRegister->assertCreated();
    $bSession = ssSessionId($bRegister);

    expect($aSession)->not->toBe($bSession);

    $category = Category::create(['name' => 'Isolation Cat', 'type' => 'expense']);

    // User A creates an expense.
    ssResetRequestState();
    $creation = $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $aSession)
        ->postJson('/api/expenses', [
            'amount' => 100,
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'description' => 'A expense',
        ]);
    $creation->assertCreated();
    expect($creation->json('data.user_id'))->toBe(
        User::where('email', 'usera@example.com')->value('id')
    );
    $expenseId = $creation->json('data.id');

    // User B must not be able to read it.
    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $bSession)
        ->getJson("/api/expenses/{$expenseId}")
        ->assertStatus(403);

    // User B only sees their own (empty) list.
    ssResetRequestState();
    $this->withHeaders(ssStatefulHeaders())
        ->withCredentials()
        ->withCookie(ssSessionCookieName(), $bSession)
        ->getJson('/api/expenses')
        ->assertOk()
        ->assertJsonCount(0, 'data.data');
});
