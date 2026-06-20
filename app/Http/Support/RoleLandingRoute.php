<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Enums\UserRole;

/**
 * SSOT: UserRole → post-login route name (SPEC §7.2, §10.1). Used by the real-login
 * path now and the deferred demo-login path later — never duplicated. Exhaustive
 * match: adding a role is a compile-time obligation.
 */
final class RoleLandingRoute
{
    public static function for(UserRole $role): string
    {
        return match ($role) {
            UserRole::SuperAdmin,
            UserRole::Administrator,
            UserRole::Manager,
            UserRole::Editor => 'admin.dashboard',
        };
    }
}
