<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\RepresentativeData;
use App\Domain\Organization\Models\Representative;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Update a Representative (SPEC §6.3.5). Plain CRUD. A newly uploaded photo replaces
 * the previous one; the old physical file is removed only AFTER the DB write commits,
 * and a freshly written file is cleaned up on rollback — no orphaned bytes either way.
 *
 * SECURITY — tenant write confinement (mirrors the Article author-stamp pattern):
 * the effective organization_id is resolved SERVER-SIDE from the
 * {@see OrganizationContext}, NOT trusted from the payload — a CONFINED manager can only
 * write into their own org (cannot move the rep to another tenant via the payload). The
 * branch_id is then asserted to BELONG to that resolved org (scope-free existence check):
 * neither a confined manager nor an unconfined admin can re-attach the rep to a foreign
 * org's branch. A mismatch is a 302 + branch_id field error, never a cross-org attach.
 */
final class UpdateRepresentativeAction
{
    use ResolvesBranchForOrganization;

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(Representative $representative, RepresentativeData $data): Representative
    {
        $organizationId = $this->context->isUnconfined()
            ? $data->organization_id
            : $this->context->organizationId();

        $this->assertBranchBelongsToOrganization($data->branch_id, $organizationId);

        $oldPhotoPath = $representative->photo_path;
        $newPhotoPath = $data->photo?->store('representatives/photos', 'public');

        $attributes = [
            'organization_id' => $organizationId,
            'branch_id' => $data->branch_id,
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'shift' => $data->shift,
            'is_coordinator' => $data->is_coordinator,
        ];

        if (is_string($newPhotoPath)) {
            $attributes['photo_path'] = $newPhotoPath;
        }

        try {
            DB::transaction(function () use ($representative, $attributes): void {
                $representative->fill($attributes)->save();
            });
        } catch (\Throwable $e) {
            if (is_string($newPhotoPath)) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }

        if (is_string($newPhotoPath) && is_string($oldPhotoPath) && $oldPhotoPath !== $newPhotoPath) {
            Storage::disk('public')->delete($oldPhotoPath);
        }

        return $representative;
    }
}
