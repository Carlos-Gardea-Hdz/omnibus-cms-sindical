<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\OrganizationData;
use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Update an Organization (SPEC §6.3.2). Slug uniqueness is unique-ignore-self. A
 * newly uploaded logo replaces the previous one; the old physical file is removed
 * only AFTER the DB write commits, and a freshly written file is cleaned up on
 * rollback — no orphaned bytes either way. director_id is NOT touched here (it is
 * wired by the Director Actions). The logo is optional on update (ORG-01 requires it
 * only on create).
 */
final class UpdateOrganizationAction
{
    public function handle(Organization $organization, OrganizationData $data): Organization
    {
        $slug = Str::slug($data->slug ?: $data->name);

        $this->assertSlugAvailable($slug, $organization);

        $oldLogoPath = $organization->logo_path;
        $newLogoPath = $data->logo?->store('organizations/logos', 'public');

        $attributes = [
            'municipality_id' => $data->municipality_id,
            'name' => $data->name,
            'slug' => $slug,
            'registered_at' => $data->registered_at,
        ];

        if (is_string($newLogoPath)) {
            $attributes['logo_path'] = $newLogoPath;
        }

        try {
            DB::transaction(function () use ($organization, $attributes): void {
                $organization->fill($attributes)->save();
            });
        } catch (\Throwable $e) {
            if (is_string($newLogoPath)) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $e;
        }

        if (is_string($newLogoPath) && is_string($oldLogoPath) && $oldLogoPath !== $newLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return $organization;
    }

    private function assertSlugAvailable(string $slug, Organization $organization): void
    {
        $taken = Organization::withTrashed()
            ->where('slug', $slug)
            ->whereKeyNot($organization->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('organizations.error.slug_taken'),
            ]);
        }
    }
}
