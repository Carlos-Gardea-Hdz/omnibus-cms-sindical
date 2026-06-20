<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\DirectorData;
use App\Domain\Organization\Exceptions\DirectorAlreadyAssignedException;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
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
 */
final class CreateDirectorAction
{
    public function handle(DirectorData $data): Director
    {
        $this->assertOrganizationHasNoDirector($data->organization_id);

        $photoPath = $data->photo?->store('directors/photos', 'public');

        try {
            return DB::transaction(function () use ($data, $photoPath): Director {
                $director = Director::create([
                    'organization_id' => $data->organization_id,
                    'first_name' => $data->first_name,
                    'last_name' => $data->last_name,
                    'photo_path' => $photoPath ?: null,
                ]);

                Organization::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($data->organization_id)
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

    private function assertOrganizationHasNoDirector(int $organizationId): void
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
