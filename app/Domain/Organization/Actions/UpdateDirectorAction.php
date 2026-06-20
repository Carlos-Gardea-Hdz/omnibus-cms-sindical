<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Data\DirectorData;
use App\Domain\Organization\Models\Director;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Update a Director (SPEC §6.3.4). organization_id is NOT moved here (a director is
 * bound to its org for life; reassignment would be a separate operation). A newly
 * uploaded photo replaces the previous one; the old physical file is removed only
 * AFTER the DB write commits, and a freshly written file is cleaned up on rollback —
 * no orphaned bytes either way.
 */
final class UpdateDirectorAction
{
    public function handle(Director $director, DirectorData $data): Director
    {
        $oldPhotoPath = $director->photo_path;
        $newPhotoPath = $data->photo?->store('directors/photos', 'public');

        $attributes = [
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
        ];

        if (is_string($newPhotoPath)) {
            $attributes['photo_path'] = $newPhotoPath;
        }

        try {
            DB::transaction(function () use ($director, $attributes): void {
                $director->fill($attributes)->save();
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

        return $director;
    }
}
