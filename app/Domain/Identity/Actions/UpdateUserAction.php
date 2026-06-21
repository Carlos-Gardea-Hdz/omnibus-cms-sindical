<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\UpdateUserData;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\CannotAssignSuperAdminException;
use App\Domain\Identity\Exceptions\SuperAdminSelfEditException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a user within the acting user's authority (SPEC §3.1 AUTH-04, slice 008).
 *
 * Guards (all server-side, actor-dependent), in order:
 *
 *   1. SUPER_ADMIN OWN-RECORD RULE (Decision C) — the sole super_admin row may be
 *      edited ONLY by itself; and a super_admin may NOT demote itself via a plain
 *      update (the singleton can only change hands as a side-effect of promoting
 *      someone else). Both violations → SuperAdminSelfEditException (`role` field).
 *
 *   2. NO SELF-ELEVATION / NO MINTING SUPER_ADMIN — a non-super_admin actor may never
 *      assign the super_admin role (to anyone, including itself) →
 *      CannotAssignSuperAdminException (`role` field).
 *
 *   3. SUPER_ADMIN SINGLETON SWAP — promoting some OTHER user to super_admin demotes
 *      the current super_admin (the actor) to administrator in the SAME transaction,
 *      so the system always holds exactly one (multi-row → DB::transaction).
 *
 * Org-confinement of WHICH users an administrator may reach is enforced at the route /
 * controller boundary (a cross-org {user} resolves to a 404 via an explicit scoped
 * lookup — the User model carries no global scope, Deviation A); this Action re-asserts
 * nothing about org because, by the time it runs, the target is already an in-authority
 * row. The password is updated ONLY when present (blank = keep the current hash), via
 * the model's `hashed` cast (never logged). No Illuminate\Http import.
 */
final class UpdateUserAction
{
    public function handle(UpdateUserData $data, User $target, User $actor): User
    {
        $this->assertSuperAdminOwnRecord($data->role, $target, $actor);
        $this->assertActorMayAssign($data->role, $actor);

        return DB::transaction(function () use ($data, $target, $actor): User {
            // Promoting a DIFFERENT user to super_admin: demote the current holder
            // (the acting super_admin) so exactly one remains after the swap.
            if ($data->role === UserRole::SuperAdmin && ! $target->is($actor)) {
                $this->demoteCurrentSuperAdmin();
            }

            $attributes = [
                'username' => $data->username,
                'name' => $data->name,
                'email' => $data->email,
                'role' => $data->role,
            ];

            // organization_id is moved only by a super_admin actor (cross-org). A
            // non-super_admin update never repositions a user across tenants.
            if ($actor->role === UserRole::SuperAdmin) {
                $attributes['organization_id'] = $data->organization_id;
            }

            if ($data->password !== null && $data->password !== '') {
                $attributes['password'] = $data->password; // hashed by the model cast
            }

            $target->update($attributes);

            return $target->refresh();
        });
    }

    /**
     * Decision C: protect the super_admin singleton's own record. The sole super_admin
     * may be edited only by itself, and may not demote itself via a plain update.
     *
     * @throws SuperAdminSelfEditException
     */
    private function assertSuperAdminOwnRecord(UserRole $newRole, User $target, User $actor): void
    {
        if ($target->role !== UserRole::SuperAdmin) {
            return;
        }

        // Another actor targeting the super_admin row.
        if (! $target->is($actor)) {
            throw new SuperAdminSelfEditException(__('users.error.super_admin_self_only'));
        }

        // The super_admin demoting itself via a plain update (forbidden — demotion is
        // only ever a side-effect of promoting someone else).
        if ($newRole !== UserRole::SuperAdmin) {
            throw new SuperAdminSelfEditException(__('users.error.super_admin_self_only'));
        }
    }

    /**
     * A non-super_admin actor may never assign the super_admin role.
     *
     * @throws CannotAssignSuperAdminException
     */
    private function assertActorMayAssign(UserRole $newRole, User $actor): void
    {
        if ($newRole === UserRole::SuperAdmin && $actor->role !== UserRole::SuperAdmin) {
            throw new CannotAssignSuperAdminException(__('users.error.cannot_assign_super_admin'));
        }
    }

    /**
     * Demote the single current super_admin to administrator (the swap's first half).
     * Runs inside the caller's transaction; UNCONFINED (User has no global scope).
     */
    private function demoteCurrentSuperAdmin(): void
    {
        User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->update(['role' => UserRole::Administrator->value]);
    }
}
