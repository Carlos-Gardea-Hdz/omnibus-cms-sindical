<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Director (soft delete, SPEC §6.3.4). The organization's 1:1 pointer
 * (organizations.director_id) is nulled in the same transaction so the slot opens up
 * for a new director (the DB FK is SET NULL, but nulling explicitly keeps the
 * application state consistent immediately and frees the one-per-org check). No
 * referential pre-check is needed — nothing references a director under restrict.
 */
final class DeleteDirectorAction
{
    public function handle(Director $director): void
    {
        DB::transaction(static function () use ($director): void {
            Organization::withoutGlobalScope(OrganizationScope::class)
                ->where('director_id', $director->getKey())
                ->update(['director_id' => null]);

            $director->delete();
        });
    }
}
