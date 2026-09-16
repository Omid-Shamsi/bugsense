<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

it('logs in an active user, regenerates the session, and exposes it at /api/v1/me', function () {
    $user = User::factory()->create([
        'email' => 'active@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $previousSessionId = session()->getId();

    $this->postJson('/login', [
        'email' => 'active@example.com',
        'password' => 'secret123',
    ])->assertNoContent();

    expect(session()->getId())->not->toBe($previousSessionId);
    $this->assertAuthenticatedAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $user->id,
                'email' => 'active@example.com',
                'display_name' => $user->display_name,
                'is_system_admin' => false,
            ],
        ]);
});

it('denies login for an inactive user without disclosing why', function () {
    User::factory()->inactive()->create([
        'email' => 'inactive@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/login', [
        'email' => 'inactive@example.com',
        'password' => 'secret123',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    $this->assertGuest();
});

it('denies login for an incorrect password with the same generic message', function () {
    User::factory()->create([
        'email' => 'active@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/login', [
        'email' => 'active@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    $this->assertGuest();
});

it('rejects login when required fields are missing', function () {
    $response = $this->postJson('/login', []);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.email.0', 'The email field is required.');
    $response->assertJsonPath('errors.password.0', 'The password field is required.');
});

it('logs out the current session and revokes access to protected routes', function () {
    $user = User::factory()->create([
        'email' => 'active@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $this->postJson('/login', [
        'email' => 'active@example.com',
        'password' => 'secret123',
    ])->assertNoContent();

    $this->getJson('/api/v1/me')->assertOk();

    $this->postJson('/logout')->assertNoContent();

    $this->assertGuest('web');

    // A real request re-resolves guards from scratch; simulate that here since
    // this test process otherwise keeps Sanctum's cached RequestGuard user
    // from the /api/v1/me call above across this single test method.
    Auth::forgetGuards();

    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('returns a problem+json 401 for /api/v1/me without a session', function () {
    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(401);
    $response->assertHeader('Content-Type', 'application/problem+json');
    $response->assertJson([
        'type' => 'about:blank',
        'title' => 'Unauthenticated',
        'status' => 401,
        'instance' => 'api/v1/me',
    ]);
});

it('returns a problem+json 404 for an unknown api route', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'application/problem+json');
    $response->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
});

it('returns a problem+json 401 for /api/v1/me even without an explicit Accept header', function () {
    $response = $this->get('/api/v1/me');

    $response->assertStatus(401);
    $response->assertHeader('Content-Type', 'application/problem+json');
});

it('rejects a login request through the real CSRF middleware with a problem+json 419', function () {
    // Laravel's CSRF middleware auto-bypasses verification when the app is
    // running unit tests. Force the real check to run so this exercises the
    // actual PreventRequestForgery middleware, not an isolated exception render.
    app()->instance('env', 'local');

    User::factory()->create([
        'email' => 'active@example.com',
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/login', [
        'email' => 'active@example.com',
        'password' => 'secret123',
    ]);

    $response->assertStatus(419);
    $response->assertHeader('Content-Type', 'application/problem+json');
    $response->assertJson([
        'type' => 'about:blank',
        'title' => 'CSRF Token Mismatch',
        'status' => 419,
    ]);
    $this->assertGuest('web');
});

it('returns a generic problem+json 500 for an unexpected exception without leaking debug details', function () {
    Route::get('/api/v1/__diagnostic-boom', function () {
        throw new RuntimeException('sensitive internal detail');
    });

    $response = $this->getJson('/api/v1/__diagnostic-boom');

    $response->assertStatus(500);
    $response->assertHeader('Content-Type', 'application/problem+json');
    $response->assertJson([
        'type' => 'about:blank',
        'title' => 'Server Error',
        'status' => 500,
        'detail' => 'An unexpected error occurred.',
    ]);
    $response->assertJsonMissingPath('exception');
    $response->assertJsonMissingPath('trace');
    $response->assertJsonMissingPath('file');
    $response->assertJsonMissingPath('line');
    $response->assertDontSeeText('sensitive internal detail');
});
