<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\UserData;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Exceptions\CannotAssignSuperAdminException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a user within the acting user's authority (SPEC §3.1 AUTH-04, slice 008).
 *
 * Three guards, all server-side and actor-dependent:
 *
 *   1. ASSIGNABLE SET — the new role must be one the actor may grant:
 *        administrator ⇒ {editor, manager, administrator}
 *        super_admin   ⇒ {editor, manager, administrator, super_admin}
 *      An administrator minting a super_admin throws CannotAssignSuperAdminException
 *      (rendered as a 302 + `role` field error), so privilege escalation is impossible.
 *
 *   2. TENANT STAMP — for any non-super_admin actor the organization_id is taken from
 *      the ACTOR (`$actor->organization_id`), NEVER the payload, so a confined
 *      administrator can only create users inside their own org. Only a super_admin may
 *      honour a payload organization_id (cross-org placement).
 *
 *   3. SUPER_ADMIN SINGLETON — if the new role is super_admin, the current super_admin
 *      (if any) is demoted to administrator in the SAME transaction before the new row
 *      is created, so the system always holds exactly one super_admin (the AUTH-04
 *      singleton). The swap touches multiple rows → DB::transaction.
 *
 * The password is set via the model's `hashed` cast (never logged or returned). This
 * Action imports no Illuminate\Http — guards surface as domain exceptions the HTTP
 * layer renders.
 */
final class CreateUserAction
{
    public function handle(UserData $data, User $actor): User
    {
        $this->assertActorMayAssign($data->role, $actor);

        $organizationId = $actor->role === UserRole::SuperAdmin
            ? $data->organization_id
            : $actor->organization_id;

        return DB::transaction(function () use ($data, $organizationId): User {
            if ($data->role === UserRole::SuperAdmin) {
                $this->demoteCurrentSuperAdmin();
            }

            return User::create([
                'username' => $data->username,
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password, // hashed by the model cast
                'role' => $data->role,
                'organization_id' => $organizationId,
            ]);
        });
    }

    /**
     * The actor may assign the target role only if it is within the actor's set. A
     * non-super_admin may NEVER assign super_admin (no privilege escalation).
     *
     * @throws CannotAssignSuperAdminException
     */
    private function assertActorMayAssign(UserRole $role, User $actor): void
    {
        if ($role === UserRole::SuperAdmin && $actor->role !== UserRole::SuperAdmin) {
            throw new CannotAssignSuperAdminException(__('users.error.cannot_assign_super_admin'));
        }
    }

    /**
     * Demote the single current super_admin (if one exists) to administrator, so the
     * caller may create the new super_admin and the singleton invariant holds. Runs
     * inside the caller's transaction. UNCONFINED by nature (User carries no global
     * OrganizationScope — Deviation A — so this reaches the super_admin in any org).
     */
    private function demoteCurrentSuperAdmin(): void
    {
        User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->update(['role' => UserRole::Administrator->value]);
    }
}
