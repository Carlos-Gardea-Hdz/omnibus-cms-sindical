<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationScope;
use Illuminate\Validation\ValidationException;

/**
 * Shared tenant guard for the Representative create/update Actions (slice-003 security
 * retrofit). Asserts that a chosen branch_id physically belongs to the representative's
 * RESOLVED organization id.
 *
 * The check runs WITHOUT the global {@see OrganizationScope}: for an unconfined admin the
 * scope is a no-op, so a raw scoped lookup would silently pass an org-Y branch when the
 * admin picked org X. By matching organization_id EXPLICITLY (scope-free) the invariant
 * holds symmetrically for both a confined manager (a foreign branch is already invisible)
 * AND an unconfined admin (who could otherwise cross-attach). A mismatch is a 302 +
 * branch_id field error (ValidationException), never a cross-org attach reaching the DB.
 */
trait ResolvesBranchForOrganization
{
    private function assertBranchBelongsToOrganization(int $branchId, ?int $organizationId): void
    {
        $belongs = Branch::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($branchId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'branch_id' => __('representatives.error.branch_org_mismatch'),
            ]);
        }
    }
}
