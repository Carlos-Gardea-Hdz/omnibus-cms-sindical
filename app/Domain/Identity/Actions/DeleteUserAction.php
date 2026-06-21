<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\CannotDeleteLastSuperAdminException;
use App\Domain\Identity\Exceptions\CannotDeleteSelfException;
use App\Models\User;

/**
 * Delete a user (SPEC §3.1 AUTH-04, slice 008). Two guards:
 *
 *   1. A user may not delete THEMSELF (CannotDeleteSelfException) — no actor can lock
 *      itself out mid-session.
 *   2. The SOLE super_admin may not be deleted (CannotDeleteLastSuperAdminException) —
 *      the singleton invariant requires the system always retain one.
 *
 * Otherwise the user is SOFT-deleted (`$target->delete()` with the SoftDeletes trait
 * from the slice-008 migration). Soft-delete is the FK-safe path: a user who authored
 * articles keeps its row, so `articles.author_id` still resolves (the author shows as
 * trashed) rather than tripping the restrict FK into a 500 (the SPEC §3.3 graceful-
 * author-delete rule). No DB::transaction needed — a single-row soft-delete. No
 * Illuminate\Http import; the guards surface as domain exceptions the HTTP layer
 * renders as a 302 + flash.
 */
final class DeleteUserAction
{
    public function handle(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw new CannotDeleteSelfException(__('users.error.cannot_delete_self'));
        }

        if ($this->isLastSuperAdmin($target)) {
            throw new CannotDeleteLastSuperAdminException(__('users.error.cannot_delete_last_super_admin'));
        }

        $target->delete();
    }

    /**
     * True when the target is a super_admin and no other super_admin exists. Counts
     * UNCONFINED (User carries no global OrganizationScope — Deviation A) so a
     * super_admin in any org is seen; excludes the target itself from the survivor
     * count.
     */
    private function isLastSuperAdmin(User $target): bool
    {
        if ($target->role !== UserRole::SuperAdmin) {
            return false;
        }

        $others = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->whereKeyNot($target->getKey())
            ->count();

        return $others === 0;
    }
}
