<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Exceptions\MunicipalityInUseException;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Municipality (hard delete — municipalities carry NO SoftDeletes). A
 * municipality referenced by any organization OR any member is refused gracefully: the
 * pre-check throws MunicipalityInUseException (rendered as a 302 + `municipality` field
 * error in bootstrap/app.php) BEFORE any mutation, so the restrict FK on organizations
 * AND members is never tripped and no 500 reaches the user (SPEC §3.2 ORG-05; slice-005
 * cross-slice lesson — a new RESTRICT FK to municipalities widens this pre-check).
 */
final class DeleteMunicipalityAction
{
    public function handle(Municipality $municipality): void
    {
        if ($this->isReferenced($municipality)) {
            throw new MunicipalityInUseException(__('municipalities.error.in_use'));
        }

        DB::transaction(static fn (): ?bool => $municipality->delete());
    }

    private function isReferenced(Municipality $municipality): bool
    {
        // withoutGlobalScope is unnecessary for Organization (not org-scoped) but
        // REQUIRED for Member (org-scoped) — without it a confined caller would only
        // see their own org's members and could wrongly pass the pre-check. withTrashed()
        // matters for both: a soft-deleted org/member row still physically holds the
        // municipality_id FK, so the RESTRICT constraint would trip on it too. The
        // pre-check must see every referrer, scope-free and trashed, for the graceful
        // refusal to be honest.
        $byOrg = Organization::withTrashed()
            ->where('municipality_id', $municipality->getKey())
            ->exists();

        $byMember = Member::withoutGlobalScope(OrganizationScope::class)
            ->withTrashed()
            ->where('municipality_id', $municipality->getKey())
            ->exists();

        return $byOrg || $byMember;
    }
}
