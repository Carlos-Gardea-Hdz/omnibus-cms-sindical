<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\DemoPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * Demo-login sandbox contract (CONTRACT §A, SPEC §3.1 AUTH-02 + the UNIGES slice-006
 * demo-mode mirror). A demo visitor picks a preset, an ephemeral org-scoped user is
 * minted in ONE transaction, the web guard logs it in, and the session carries the
 * `is_demo` flag + a 30-minute sliding TTL. The session is a read-only sandbox: a
 * demo user can NEVER reach a super_admin route nor mutate via any destructive route.
 * Web validation is 302 + session errors (never 422). Boots PostgreSQL 18.
 *
 * The IP rate-limit (10 demo-logins / hour, AUTH-02) is cleared per-test so the
 * limiter state cannot bleed across cases.
 */

beforeEach(function (): void {
    RateLimiter::clear('demo-login');
});

it('mints an ephemeral demo user for each preset, authenticates it, lands the dashboard, and flags the session demo', function (DemoPreset $preset): void {
    $before = User::query()->count();

    post(route('demo.store'), ['preset' => $preset->value])
        ->assertRedirect(route('admin.dashboard'));

    assertAuthenticated();

    // Exactly one ephemeral user was created with the preset's role + the demo marker.
    expect(User::query()->count())->toBe($before + 1);

    $demoUser = User::query()->whereNotNull('demo_session_id')->latest('id')->firstOrFail();

    expect($demoUser->role)->toBe($preset->role())
        ->and($demoUser->is_demo)->toBeTrue()
        ->and($demoUser->organization_id)->not->toBeNull();

    expect(session('is_demo'))->toBeTrue()
        ->and(session('demo_preset'))->toBe($preset->value)
        ->and(session('demo_expires_at'))->toBeInt();
})->with(function (): iterable {
    foreach (DemoPreset::cases() as $preset) {
        yield $preset->value => [$preset];
    }
});

it('rejects a preset outside the allowed set (e.g. super_admin) with a 302 + preset error and no user, no session', function (string $forged): void {
    $before = User::query()->count();

    from(route('demo.create'))
        ->post(route('demo.store'), ['preset' => $forged])
        ->assertRedirect(route('demo.create'))
        ->assertSessionHasErrors('preset');

    assertGuest();

    expect(User::query()->count())->toBe($before)
        ->and(session('is_demo'))->toBeNull();
})->with([
    'super_admin (the load-bearing block)' => ['super_admin'],
    'unknown preset' => ['root'],
    'empty preset' => [''],
]);

it('rate-limits demo logins to 10 per IP per hour: the 11th is a 302 + preset error and mints no user', function (): void {
    // Burn the allowance: 10 successful demo logins from this IP.
    for ($i = 0; $i < 10; $i++) {
        post(route('demo.store'), ['preset' => DemoPreset::Editor->value])->assertRedirect();
        // Drop the session auth so each call is a fresh attempt, not a re-entrant one.
        app('auth')->guard('web')->logout();
        session()->flush();
    }

    $after10 = User::query()->whereNotNull('demo_session_id')->count();
    expect($after10)->toBe(10);

    // The 11th attempt is throttled — 302 back with a `preset` error, NO new user.
    from(route('demo.create'))
        ->post(route('demo.store'), ['preset' => DemoPreset::Editor->value])
        ->assertRedirect()
        ->assertSessionHasErrors('preset');

    expect(User::query()->whereNotNull('demo_session_id')->count())->toBe(10);
});

it('expires a demo session past its 30-minute TTL: the next admin request logs out and redirects to login', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    post(route('demo.store'), ['preset' => DemoPreset::Administrator->value])
        ->assertRedirect(route('admin.dashboard'));

    assertAuthenticated();

    // Jump 31 minutes forward — past the TTL.
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:31:00'));

    get(route('admin.dashboard'))
        ->assertRedirect(route('login'));

    assertGuest();

    Carbon::setTestNow();
});

it('slides the TTL forward on continued activity so an active demo session never expires', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    post(route('demo.store'), ['preset' => DemoPreset::Administrator->value])
        ->assertRedirect(route('admin.dashboard'));

    // 20 minutes later (within the window) — activity slides the expiry forward.
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:20:00'));
    get(route('admin.dashboard'))->assertOk();

    // 25 minutes after THAT (45 min after login, but only 25 since last activity) — still alive.
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:45:00'));
    get(route('admin.dashboard'))->assertOk();

    assertAuthenticated();

    Carbon::setTestNow();
});

it('forbids a demo administrator from reaching a super_admin-only route (the role gate runs before the demo block)', function (): void {
    post(route('demo.store'), ['preset' => DemoPreset::Administrator->value])
        ->assertRedirect(route('admin.dashboard'));

    // /admin/organizations is role:super_admin — a demo administrator is a 403, never a redirect.
    get(route('admin.organizations.index'))->assertForbidden();
});

it('lets a demo session READ the dashboard and the article list (read-only is allowed)', function (): void {
    post(route('demo.store'), ['preset' => DemoPreset::Editor->value])
        ->assertRedirect(route('admin.dashboard'));

    get(route('admin.dashboard'))->assertOk();
    get(route('admin.articles.index'))->assertOk();
});

it('blocks every destructive mutation route for a demo session with a 302 + demo.blocked, leaving state untouched', function (string $method, string $routeName, array $params): void {
    post(route('demo.store'), ['preset' => DemoPreset::Administrator->value])
        ->assertRedirect(route('admin.dashboard'));

    $userCountBefore = User::query()->count();

    $response = match ($method) {
        'post' => post(route($routeName, $params), []),
        'put' => $this->put(route($routeName, $params), []),
        'delete' => $this->delete(route($routeName, $params)),
    };

    // A blocked write redirects back with the demo.blocked flash — NEVER a 200/302-to-success.
    $response->assertStatus(302);

    expect(session('error'))->toBe(__('demo.blocked'));

    // No row was created or destroyed by the blocked attempt.
    expect(User::query()->count())->toBe($userCountBefore);
})->with([
    'article store' => ['post', 'admin.articles.store', []],
    'article destroy' => ['delete', 'admin.articles.destroy', ['article' => 1]],
    'article publish' => ['post', 'admin.articles.publish', ['article' => 1]],
    'category store' => ['post', 'admin.categories.store', []],
    'category destroy' => ['delete', 'admin.categories.destroy', ['category' => 1]],
    'job store' => ['post', 'admin.jobs.store', []],
    'job destroy' => ['delete', 'admin.jobs.destroy', ['job' => 1]],
    'job status' => ['post', 'admin.jobs.status', ['job' => 1]],
    'member approve' => ['post', 'admin.members.approve', ['member' => 1]],
    'member reject' => ['post', 'admin.members.reject', ['member' => 1]],
    'municipality store' => ['post', 'admin.municipalities.store', []],
    'municipality destroy' => ['delete', 'admin.municipalities.destroy', ['municipality' => 1]],
    'user store' => ['post', 'admin.users.store', []],
    'user destroy' => ['delete', 'admin.users.destroy', ['user' => 1]],
]);

it('clears the is_demo flag when a demo session logs out', function (): void {
    post(route('demo.store'), ['preset' => DemoPreset::Editor->value])
        ->assertRedirect(route('admin.dashboard'));

    expect(session('is_demo'))->toBeTrue();

    post(route('logout'))->assertRedirect();

    assertGuest();
    expect(session('is_demo'))->toBeNull();
});

it('keeps a real (non-demo) login completely outside the demo machinery', function (): void {
    $real = User::factory()->editor()->create([
        'username' => 'realuser',
        'password' => 'password',
    ]);

    post(route('login.store'), ['username' => 'realuser', 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard'));

    actingAs($real);

    expect(session('is_demo'))->toBeNull();
    expect($real->fresh()->is_demo)->toBeFalse();
    expect($real->fresh()->demo_session_id)->toBeNull();

    // A real user is NOT subject to the destructive-route block: it can reach the user index.
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.users.index'))
        ->assertOk();
});
