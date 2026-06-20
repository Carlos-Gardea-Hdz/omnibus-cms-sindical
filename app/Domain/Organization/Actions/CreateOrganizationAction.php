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
 * Create an Organization (SPEC §6.3.2). Derives a unique slug from the supplied slug
 * or the name, asserts uniqueness (the same DTO serves store + update, so uniqueness
 * lives here, not in a DTO attribute), and stores the logo on disk inside the
 * transaction's success path. On rollback the freshly written logo is removed so no
 * orphaned bytes survive. super_admin runs unconfined, so no scope bypass is needed.
 */
final class CreateOrganizationAction
{
    public function handle(OrganizationData $data): Organization
    {
        $slug = Str::slug($data->slug ?: $data->name);

        $this->assertSlugAvailable($slug);

        $logoPath = $data->logo?->store('organizations/logos', 'public');

        try {
            return DB::transaction(fn (): Organization => Organization::create([
                'municipality_id' => $data->municipality_id,
                'director_id' => null,
                'name' => $data->name,
                'slug' => $slug,
                'logo_path' => $logoPath ?: null,
                'registered_at' => $data->registered_at,
            ]));
        } catch (\Throwable $e) {
            if (is_string($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            throw $e;
        }
    }

    private function assertSlugAvailable(string $slug): void
    {
        if (Organization::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('organizations.error.slug_taken'),
            ]);
        }
    }
}
