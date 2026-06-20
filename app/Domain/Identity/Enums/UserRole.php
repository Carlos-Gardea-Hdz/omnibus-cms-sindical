<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * 4-level RBAC strict ladder (SPEC §3.1 AUTH-03, §10.2).
 * super_admin (4) > administrator (3) > manager (2) > editor (1).
 * A higher level satisfies every lower-level requirement.
 */
#[TypeScript]
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Administrator = 'administrator';
    case Manager = 'manager';
    case Editor = 'editor';

    /** Ladder rank — higher number = more privilege. */
    public function level(): int
    {
        return match ($this) {
            self::SuperAdmin => 4,
            self::Administrator => 3,
            self::Manager => 2,
            self::Editor => 1,
        };
    }

    /** i18n key resolved client-side and via __() server-side: role.super_admin, etc. */
    public function labelKey(): string
    {
        return 'role.'.$this->value;
    }

    /** Magenta-palette accent per role (SPEC §1.4) for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::SuperAdmin => '#9211CF',     // primary-dark
            self::Administrator => '#DD00FF',  // primary
            self::Manager => '#E647FF',        // primary-light
            self::Editor => '#A03CC7',         // danger/neutral accent
        };
    }

    /** Ladder gate: does this role meet or exceed the required minimum level? */
    public function hasAtLeast(self $minimum): bool
    {
        return $this->level() >= $minimum->level();
    }
}
