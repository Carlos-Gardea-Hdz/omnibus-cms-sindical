<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * Logout contract (CONTRACT §8, SPEC §10.1): LoginController@destroy clears the
 * web guard, invalidates the session, and regenerates the CSRF token, then
 * redirects to the login screen. The route is gated by `auth`, so a guest is
 * routed through the auth gate to login (a 302), never a 403. Boots the app +
 * PostgreSQL 18 via RefreshDatabase.
 */

it('logs the authenticated user out and redirects to login', function (): void {
    $user = User::factory()->administrator()->create();

    actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    assertGuest();
});

it('logs an editor out and redirects to login', function (): void {
    $user = User::factory()->editor()->create();

    actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    assertGuest();
});

it('rotates the CSRF token on logout so the old session token no longer applies', function (): void {
    $user = User::factory()->superAdmin()->create();

    actingAs($user);
    $tokenBefore = session()->token();

    post(route('logout'))->assertRedirect(route('login'));

    expect(session()->token())->not->toBe($tokenBefore);
    assertGuest();
});

it('routes a guest who hits logout through the auth gate to login, never a 403', function (): void {
    post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertStatus(302);

    assertGuest();
});
