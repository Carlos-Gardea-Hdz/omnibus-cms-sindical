<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\DemoPreset;
use App\Domain\Identity\Enums\UserRole;

/*
 * The load-bearing demo invariant (CONTRACT §A, SPEC §3.1 AUTH-02). DemoPreset is a
 * string-backed enum whose case set is the WHOLE attack surface of the public demo:
 * if a `super_admin` case ever appeared, a demo visitor could be minted a super_admin
 * and reach the super_admin-gated catalog/user CRUD. These pure-enum assertions pin
 * that the case set is EXACTLY {administrator, manager, editor} and that every preset's
 * mapped role sits strictly BELOW super_admin on the ladder. No DB, no app boot.
 */

it('exposes exactly the three non-privileged presets and NEVER a super_admin', function (): void {
    $values = array_map(static fn (DemoPreset $p): string => $p->value, DemoPreset::cases());

    sort($values);

    expect($values)->toBe(['administrator', 'editor', 'manager'])
        ->and($values)->not->toContain('super_admin');
});

it('maps every preset to a role strictly below super_admin on the ladder', function (DemoPreset $preset): void {
    $role = $preset->role();

    expect($role)->toBeInstanceOf(UserRole::class)
        ->and($role)->not->toBe(UserRole::SuperAdmin)
        ->and($role->level())->toBeLessThan(UserRole::SuperAdmin->level());
})->with(function (): iterable {
    foreach (DemoPreset::cases() as $preset) {
        yield $preset->value => [$preset];
    }
});

it('maps each preset to its matching role value', function (): void {
    expect(DemoPreset::Administrator->role())->toBe(UserRole::Administrator)
        ->and(DemoPreset::Manager->role())->toBe(UserRole::Manager)
        ->and(DemoPreset::Editor->role())->toBe(UserRole::Editor);
});

it('derives stable i18n keys for each preset, never leaking real PII in the display name', function (DemoPreset $preset): void {
    expect($preset->labelKey())->toBe("demo.preset.{$preset->value}.title")
        ->and($preset->descriptionKey())->toBe("demo.preset.{$preset->value}.desc")
        ->and($preset->displayName())->not->toBe('');
})->with(function (): iterable {
    foreach (DemoPreset::cases() as $preset) {
        yield $preset->value => [$preset];
    }
});
