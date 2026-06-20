<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Level-based RBAC gate matrix (CONTRACT §7, SPEC §3.1 AUTH-03 / §10.2). The
 * EnsureRole middleware (alias `role`) takes ONE minimum-level token; a user's
 * UserRole passes iff it meets or exceeds that level (a higher role satisfies a
 * lower gate — the strict ladder). Authorization lives in HTTP middleware, never
 * the domain.
 *
 * The real app only ships one gated route this slice (admin.dashboard at
 * role:editor), so to exercise the full 4×4 matrix we register one throwaway
 * route per level inside the web middleware group, each gated by `auth` + the
 * matching `role:` token. Each returns 'ok' so a pass is an assertOk() and a
 * wrong-LEVEL authenticated user is a 403 (NOT a redirect); a guest is caught
 * upstream by `auth` → 302 to login. Boots the app + PostgreSQL 18.
 */

beforeEach(function (): void {
    foreach (UserRole::cases() as $level) {
        Route::middleware(['web', 'auth', "role:{$level->value}"])
            ->get("/__test/gate/{$level->value}", fn (): string => 'ok')
            ->name("__test.gate.{$level->value}");
    }
});

it('redirects a guest hitting the real dashboard to login with a 302, never a 403', function (): void {
    get(route('admin.dashboard'))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
});

it('redirects a guest hitting any level-gated route to login with a 302, never a 403', function (UserRole $level): void {
    get("/__test/gate/{$level->value}")
        ->assertRedirect(route('login'))
        ->assertStatus(302);
})->with([
    'editor gate' => [UserRole::Editor],
    'manager gate' => [UserRole::Manager],
    'administrator gate' => [UserRole::Administrator],
    'super_admin gate' => [UserRole::SuperAdmin],
]);

it('passes the full 4×4 ladder: a role gets 200 iff its level meets the required gate, else 403', function (UserRole $role, UserRole $gate): void {
    $user = User::factory()->state(['role' => $role])->create();

    $response = actingAs($user)->get("/__test/gate/{$gate->value}");

    if ($role->level() >= $gate->level()) {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
})->with(function (): iterable {
    foreach (UserRole::cases() as $role) {
        foreach (UserRole::cases() as $gate) {
            yield "{$role->value} on role:{$gate->value}" => [$role, $gate];
        }
    }
});

it('lets a super_admin through the lowest editor gate (higher role satisfies a lower gate)', function (): void {
    $user = User::factory()->superAdmin()->create();

    actingAs($user)
        ->get('/__test/gate/editor')
        ->assertOk();
});

it('forbids an editor at the administrator gate with a 403, never a redirect', function (): void {
    $user = User::factory()->editor()->create();

    actingAs($user)
        ->get('/__test/gate/administrator')
        ->assertForbidden();
});

it('admits an editor to the real role:editor dashboard route', function (): void {
    $user = User::factory()->editor()->create();

    actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk();
});
