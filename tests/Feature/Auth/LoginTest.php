<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * Session authentication contract (SPEC §3.1 AUTH-01, §10.1). The login endpoint
 * is driven by Laravel's session guard via AuthenticateUserAction; LoginData
 * (Spatie Data) is the single source of validation truth. The login key is
 * `username` (not email) — the deliberate divergence from the UNIGES reference
 * (CONTRACT §4/§5, gate decision A). These tests boot the app and run against
 * PostgreSQL 18 via RefreshDatabase.
 *
 * Web validation surfaces as a 302 redirect-back with session errors, NEVER 422.
 * Failures flash the SAME generic message on `username` for both a wrong password
 * and an unknown username — no user-enumeration.
 */

it('authenticates each role with the right password and lands it on the admin dashboard', function (UserRole $role): void {
    // Slice 001 lands all four roles on admin.dashboard (RoleLandingRoute::for,
    // CONTRACT §6, gate decision D). The username column is stored lowercased.
    $user = User::factory()->state(['role' => $role])->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    post(route('login.store'), [
        'username' => 'operator',
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));

    assertAuthenticatedAs($user);
})->with([
    'super_admin' => [UserRole::SuperAdmin],
    'administrator' => [UserRole::Administrator],
    'manager' => [UserRole::Manager],
    'editor' => [UserRole::Editor],
]);

it('lowercases the submitted username so a mixed-case login still matches the stored row', function (): void {
    // The Action lowercases/trims the username before Auth::attempt (CONTRACT §5).
    $user = User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    post(route('login.store'), [
        'username' => '  Operator  ',
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));

    assertAuthenticatedAs($user);
});

it('rejects a wrong password with a 302 + session error on username and stays guest', function (): void {
    User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    from(route('login'))
        ->post(route('login.store'), [
            'username' => 'operator',
            'password' => 'wrong-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');

    assertGuest();
});

it('rejects an unknown username with the same 302 + session error on username and stays guest', function (): void {
    from(route('login'))
        ->post(route('login.store'), [
            'username' => 'nobody',
            'password' => 'password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');

    assertGuest();
});

it('does not reveal whether the username exists: identical generic error for wrong password and unknown user', function (): void {
    User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    $expected = __('auth.failed');

    // A wrong password against an existing user flashes the generic message...
    from(route('login'))
        ->post(route('login.store'), [
            'username' => 'operator',
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors(['username' => $expected]);

    session()->forget('errors');

    // ...and so does a username that does not exist at all — identical message,
    // so the response never leaks whether the account is registered (no enumeration).
    from(route('login'))
        ->post(route('login.store'), [
            'username' => 'nobody',
            'password' => 'password',
        ])
        ->assertSessionHasErrors(['username' => $expected]);
});

it('rejects a missing username via LoginData with a 302 + session error, never a 422', function (): void {
    from(route('login'))
        ->post(route('login.store'), [
            'password' => 'password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');

    assertGuest();
});

it('rejects an over-length username (>60) via LoginData with a 302 + session error', function (): void {
    from(route('login'))
        ->post(route('login.store'), [
            'username' => str_repeat('a', 61),
            'password' => 'password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('username');

    assertGuest();
});

it('rejects a missing password via LoginData with a 302 + session error', function (): void {
    User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    from(route('login'))
        ->post(route('login.store'), [
            'username' => 'operator',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('password');

    assertGuest();
});

it('regenerates the session id on a successful login (session-fixation defense)', function (): void {
    User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
    ]);

    // Establish a session, then capture its id before authenticating.
    get(route('login'));
    $before = session()->getId();

    post(route('login.store'), [
        'username' => 'operator',
        'password' => 'password',
    ])->assertRedirect();

    expect(session()->getId())->not->toBe($before)->not->toBeEmpty();
});

it('honours remember-me, persisting a remember token', function (): void {
    // Start with no token: Auth::attempt(remember:true) sets one only when empty,
    // so this proves the remember flag reached the guard (and persisted a token).
    $user = User::factory()->editor()->create([
        'username' => 'operator',
        'password' => 'password',
        'remember_token' => null,
    ]);

    expect($user->remember_token)->toBeNull();

    post(route('login.store'), [
        'username' => 'operator',
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect();

    assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->not->toBeNull();
});
