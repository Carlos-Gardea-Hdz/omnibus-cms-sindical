<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;

/*
 * Pure-logic coverage for the UserRole backed enum (CONTRACT §1, SPEC §3.1
 * AUTH-03 / §10.2). No framework bootstrap, no database — the enum is a plain
 * value object encoding the 4-level RBAC strict ladder:
 *   super_admin (4) > administrator (3) > manager (2) > editor (1).
 * The level() ranking and the hasAtLeast() gate primitive are the load-bearing
 * contract every authorization decision (EnsureRole middleware) leans on, so the
 * full 4×4 ladder matrix is pinned explicitly — never magic strings.
 */

it('backs exactly the four SPEC §3.1 roles with their snake_case values', function (): void {
    expect(UserRole::cases())->toHaveCount(4);

    $values = array_map(fn (UserRole $role): string => $role->value, UserRole::cases());

    expect($values)->toBe([
        'super_admin',
        'administrator',
        'manager',
        'editor',
    ]);
});

it('ranks each role on the ladder: super_admin=4 > administrator=3 > manager=2 > editor=1', function (UserRole $role, int $level): void {
    expect($role->level())->toBe($level);
})->with([
    'super_admin → 4' => [UserRole::SuperAdmin, 4],
    'administrator → 3' => [UserRole::Administrator, 3],
    'manager → 2' => [UserRole::Manager, 2],
    'editor → 1' => [UserRole::Editor, 1],
]);

it('satisfies hasAtLeast for every (role, minimum) pair iff its level meets or exceeds the minimum', function (UserRole $role, UserRole $minimum): void {
    // The gate primitive must agree with the raw level comparison for the entire
    // 4×4 matrix (16 pairs) — this is exactly what EnsureRole relies on.
    expect($role->hasAtLeast($minimum))->toBe($role->level() >= $minimum->level());
})->with(function (): iterable {
    foreach (UserRole::cases() as $role) {
        foreach (UserRole::cases() as $minimum) {
            yield "{$role->value} vs {$minimum->value}" => [$role, $minimum];
        }
    }
});

it('lets super_admin clear every gate on the ladder', function (UserRole $minimum): void {
    expect(UserRole::SuperAdmin->hasAtLeast($minimum))->toBeTrue();
})->with([
    'editor gate' => [UserRole::Editor],
    'manager gate' => [UserRole::Manager],
    'administrator gate' => [UserRole::Administrator],
    'super_admin gate' => [UserRole::SuperAdmin],
]);

it('lets editor clear only the editor gate and no higher gate', function (): void {
    expect(UserRole::Editor->hasAtLeast(UserRole::Editor))->toBeTrue()
        ->and(UserRole::Editor->hasAtLeast(UserRole::Manager))->toBeFalse()
        ->and(UserRole::Editor->hasAtLeast(UserRole::Administrator))->toBeFalse()
        ->and(UserRole::Editor->hasAtLeast(UserRole::SuperAdmin))->toBeFalse();
});

it('derives a namespaced i18n label key per role: role.{value}', function (UserRole $role): void {
    expect($role->labelKey())->toBe("role.{$role->value}");
})->with([
    'super_admin' => [UserRole::SuperAdmin],
    'administrator' => [UserRole::Administrator],
    'manager' => [UserRole::Manager],
    'editor' => [UserRole::Editor],
]);

it('maps each role to its magenta-palette accent hex (SPEC §1.4)', function (UserRole $role, string $hex): void {
    expect($role->color())->toBe($hex);
})->with([
    'super_admin → primary-dark' => [UserRole::SuperAdmin, '#9211CF'],
    'administrator → primary' => [UserRole::Administrator, '#DD00FF'],
    'manager → primary-light' => [UserRole::Manager, '#E647FF'],
    'editor → neutral accent' => [UserRole::Editor, '#A03CC7'],
]);
