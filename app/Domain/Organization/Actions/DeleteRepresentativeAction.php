<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Representative;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Representative (soft delete, SPEC §6.3.5). Plain CRUD — once removed, its
 * branch is free to be deleted (the BranchHasRepresentativesException pre-check is
 * withTrashed-aware, so a soft-deleted representative still blocks the branch until
 * it is force-deleted; that is the §3.2 ORG-02 contract — the physical FK row must be
 * gone). The featured photo file is intentionally retained for soft-delete
 * recoverability (mirrors the article soft-delete, which keeps its image).
 */
final class DeleteRepresentativeAction
{
    public function handle(Representative $representative): void
    {
        DB::transaction(static fn (): ?bool => $representative->delete());
    }
}
