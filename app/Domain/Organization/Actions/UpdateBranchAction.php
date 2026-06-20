<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\BranchData;
use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationContext;

/**
 * Update a Branch (SPEC §6.3.3). A single-table write — no transaction needed.
 *
 * SECURITY — tenant write confinement (mirrors the Article author-stamp pattern):
 * the effective organization_id is resolved SERVER-SIDE from the
 * {@see OrganizationContext}, NOT trusted from the payload. Without this guard a CONFINED
 * manager of org A could PUT organization_id=B and MOVE their own branch out of their
 * tenant. When the context is CONFINED (manager/editor) we IGNORE any payload
 * organization_id and force the confined org id (the branch stays in A); only an
 * UNCONFINED admin/super_admin may re-assign the org via the payload.
 */
final class UpdateBranchAction
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Branch $branch, BranchData $data): Branch
    {
        $branch->fill([
            'organization_id' => $this->context->isUnconfined()
                ? $data->organization_id
                : $this->context->organizationId(),
            'name' => $data->name,
            'location' => $data->location,
        ])->save();

        return $branch;
    }
}
