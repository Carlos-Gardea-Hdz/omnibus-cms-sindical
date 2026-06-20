<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\BranchData;
use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationContext;

/**
 * Create a Branch (SPEC §6.3.3). A single-table write — no transaction needed.
 *
 * SECURITY — tenant write confinement (mirrors the Article author-stamp pattern):
 * the effective organization_id is resolved SERVER-SIDE from the
 * {@see OrganizationContext}, NOT trusted from the payload. The route is reachable by a
 * CONFINED manager (role:manager + org.scope), and the DTO only validates
 * organization_id with Exists(...) — never "belongs to my org". So without this guard a
 * manager of org A could POST organization_id=B and plant a row in another tenant. When
 * the context is CONFINED (manager/editor), we IGNORE any payload organization_id and
 * force the confined org id; only an UNCONFINED admin/super_admin may legitimately choose
 * the org via the payload.
 */
final class CreateBranchAction
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(BranchData $data): Branch
    {
        return Branch::create([
            'organization_id' => $this->context->isUnconfined()
                ? $data->organization_id
                : $this->context->organizationId(),
            'name' => $data->name,
            'location' => $data->location,
        ]);
    }
}
