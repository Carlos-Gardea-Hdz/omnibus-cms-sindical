<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationScope;
use Illuminate\Validation\ValidationException;

/**
 * Jobs-local tenant guard (slice-004). Asserts that a chosen branch_id physically belongs
 * to the JobPosting's RESOLVED organization id. Shared by the Create/Update JobPosting
 * Actions; mirrors the Organization domain's self-contained
 * {@see \App\Domain\Organization\Actions\ResolvesBranchForOrganization} rather than a
 * shared App\Support trait — App\Support stays domain-neutral (it must never import
 * App\Domain), so the assertion lives INSIDE the Jobs domain. Jobs MAY reference the
 * Organization Branch MODEL directly (a cross-domain FK model reference is allowed; calling
 * another domain's Actions/Services is not).
 *
 * The check runs WITHOUT the global {@see OrganizationScope}: for an unconfined admin the
 * scope is a no-op, so a raw scoped lookup would silently pass an org-Y branch when the
 * admin picked org X. By matching organization_id EXPLICITLY (scope-free) the invariant
 * holds symmetrically for both a confined manager (a foreign branch is already invisible)
 * AND an unconfined admin (who could otherwise cross-attach). A mismatch is a 302 +
 * branch_id field error (ValidationException), never a cross-org attach reaching the DB.
 */
trait AssertsBranchBelongsToOrganization
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
                'branch_id' => __('jobs.error.branch_org_mismatch'),
            ]);
        }
    }
}
