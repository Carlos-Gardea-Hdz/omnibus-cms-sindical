<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\RepresentativeData;
use App\Domain\Organization\Models\Representative;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Create a Representative (SPEC §6.3.5). Plain CRUD. The photo is stored on disk
 * inside the transaction's success path; on rollback the freshly written file is
 * removed so no orphaned bytes survive. `shift` is the validated RepresentativeShift
 * enum from the DTO.
 *
 * SECURITY — tenant write confinement (mirrors the Article author-stamp pattern):
 * the effective organization_id is resolved SERVER-SIDE from the
 * {@see OrganizationContext}, NOT trusted from the payload — a CONFINED manager can only
 * write into their own org. The branch_id is then asserted to BELONG to that resolved org
 * (an unqualified, scope-free existence check): a confined manager cannot attach a rep to
 * another org's branch, and an unconfined admin choosing org X cannot attach a branch from
 * org Y. A foreign branch is a 302 + branch_id field error, never a cross-org attach.
 */
final class CreateRepresentativeAction
{
    use ResolvesBranchForOrganization;

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(RepresentativeData $data): Representative
    {
        $organizationId = $this->context->isUnconfined()
            ? $data->organization_id
            : $this->context->organizationId();

        $this->assertBranchBelongsToOrganization($data->branch_id, $organizationId);

        $photoPath = $data->photo?->store('representatives/photos', 'public');

        try {
            return DB::transaction(fn (): Representative => Representative::create([
                'organization_id' => $organizationId,
                'branch_id' => $data->branch_id,
                'first_name' => $data->first_name,
                'last_name' => $data->last_name,
                'shift' => $data->shift,
                'is_coordinator' => $data->is_coordinator,
                'photo_path' => $photoPath ?: null,
            ]));
        } catch (\Throwable $e) {
            if (is_string($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $e;
        }
    }
}
