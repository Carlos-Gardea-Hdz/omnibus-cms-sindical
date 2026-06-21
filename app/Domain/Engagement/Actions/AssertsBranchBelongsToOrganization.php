<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationScope;
use Illuminate\Validation\ValidationException;

/**
 * Engagement-local tenant guard (slice-006). Asserts that a chosen branch_id physically
 * belongs to the contact message's chosen organization id. Used by SubmitContactAction.
 *
 * This is a domain-LOCAL copy of the slice-004 Jobs trait rather than a shared App\Support
 * trait — App\Support stays domain-neutral (it must never import App\Domain), so the
 * assertion lives INSIDE the Engagement domain. Engagement MAY reference the Organization
 * Branch MODEL directly (a cross-domain FK model reference is arch-allowed; calling another
 * domain's Actions/Services is not).
 *
 * DIFFERENCE vs the Jobs trait: here `$organizationId` is `int` (NOT `?int`) — a contact
 * message's organization_id is NOT NULL (both FKs are required public-payload choices),
 * unlike a job posting whose org is resolved from context. Both org and branch come from
 * the ANONYMOUS payload, so this assertion is the write-provenance guard: a forged
 * cross-org pair never reaches the DB.
 *
 * The check runs WITHOUT the global {@see OrganizationScope}: the public submit path is
 * unconfined, so the scope would be a no-op anyway, but querying scope-free makes the
 * invariant hold symmetrically regardless of any acting context. By matching
 * organization_id EXPLICITLY a branch from org Y is rejected when the submitter picked org
 * X. A mismatch is a 302 + branch_id field error (Spatie's ValidationException already
 * 302s + reports on `branch_id`), never a cross-org attach reaching the DB.
 */
trait AssertsBranchBelongsToOrganization
{
    private function assertBranchBelongsToOrganization(int $branchId, int $organizationId): void
    {
        $belongs = Branch::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($branchId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'branch_id' => __('contact.error.branch_org_mismatch'),
            ]);
        }
    }
}
