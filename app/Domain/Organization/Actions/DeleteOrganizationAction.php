<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Delete an Organization (soft delete). An organization that still has branches is
 * refused gracefully: the pre-check throws OrganizationInUseException (rendered as a
 * 302 + `organization` field error in bootstrap/app.php) BEFORE any mutation, so the
 * restrict FK on branches is never tripped and no 500 reaches the user (SPEC §3.2
 * ORG-01). super_admin runs unconfined, so the withoutGlobalScope on Branch is
 * belt-and-suspenders — the pre-check must see EVERY org's branches regardless of the
 * acting context, and trashed branches still physically hold the FK.
 */
final class DeleteOrganizationAction
{
    public function handle(Organization $organization): void
    {
        if ($this->hasBranches($organization)) {
            throw new OrganizationInUseException(__('organizations.error.has_branches'));
        }

        DB::transaction(static fn (): ?bool => $organization->delete());
    }

    private function hasBranches(Organization $organization): bool
    {
        return Branch::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->getKey())
            ->withTrashed()
            ->exists();
    }
}
