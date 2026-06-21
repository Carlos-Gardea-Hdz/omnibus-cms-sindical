<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\DirectorData;
use App\Domain\Organization\Exceptions\DirectorAlreadyAssignedException;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationContext;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Create a Director (SPEC §6.3.4). Asserts one-per-org (SPEC §3.2 ORG-04) BEFORE the
 * write — the DirectorAlreadyAssignedException (graceful 302) is the friendly layer,
 * the DB unique index on directors.organization_id is the backstop a raw insert
 * trips. The photo is stored on disk inside the transaction's success path; on
 * rollback the freshly written file is removed. On success the organization's 1:1
 * pointer (organizations.director_id) is wired in the same transaction.
 *
 * ORG STAMP (the write-confinement discipline, mirrors CreateBranch/Representative/
 * JobPosting): the target organization_id is the CONTEXT org for a confined actor and
 * only the payload's for an UNCONFINED actor (super_admin). A confined actor's payload
 * organization_id is therefore IGNORED — it can never plant a director into another
 * tenant via a forged payload (latent today since the Director routes are
 * role:administrator, but the guard keeps the org-stamp invariant uniform).
 */
final class CreateDirectorAction
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(DirectorData $data): Director
    {
        $organizationId = $this->context->isUnconfined()
            ? $data->organization_id
            : $this->context->organizationId();

        $this->assertOrganizationHasNoDirector($organizationId);

        $photoPath = $data->photo?->store('directors/photos', 'public');

        try {
            return DB::transaction(function () use ($data, $organizationId, $photoPath): Director {
                $director = Director::create([
                    'organization_id' => $organizationId,
                    'first_name' => $data->first_name,
                    'last_name' => $data->last_name,
                    'photo_path' => $photoPath ?: null,
                ]);

                Organization::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($organizationId)
                    ->update(['director_id' => $director->getKey()]);

                return $director;
            });
        } catch (\Throwable $e) {
            if (is_string($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $e;
        }
    }

    private function assertOrganizationHasNoDirector(?int $organizationId): void
    {
        $exists = Director::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->withTrashed()
            ->exists();

        if ($exists) {
            throw new DirectorAlreadyAssignedException(__('directors.error.already_assigned'));
        }
    }
}
