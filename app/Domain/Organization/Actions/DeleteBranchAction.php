<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Content\Models\Article;
use App\Domain\Organization\Exceptions\BranchHasRepresentativesException;
use App\Domain\Organization\Models\Branch;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a Branch — the load-bearing §3.2 ORG-02 + §11.4 #2/#3 Action.
 *
 * Step 1 — refuse if the branch still has representatives (withTrashed-aware: a
 * soft-deleted representative's row still physically holds the restrict FK on
 * representatives.branch_id). The pre-check throws BranchHasRepresentativesException
 * (302 + `branch` field error / 422 JSON in bootstrap/app.php) BEFORE any mutation,
 * so the restrict FK is never tripped and no 500 reaches the user (§11.4 #3).
 *
 * Step 2 — else, inside ONE transaction, perform an APPLICATION-LEVEL cascade
 * (never a DB ON DELETE CASCADE, §6.1): soft-delete every article on the branch and
 * remove its physical featured-image file, then soft-delete the branch itself
 * (§11.4 #2). Article is read withoutGlobalScope(OrganizationScope) so the delete
 * reaches EVERY article of the branch regardless of the acting context.
 */
final class DeleteBranchAction
{
    public function handle(Branch $branch): void
    {
        if ($this->hasRepresentatives($branch)) {
            throw new BranchHasRepresentativesException(__('branches.error.has_representatives'));
        }

        $articles = Article::withoutGlobalScope(OrganizationScope::class)
            ->where('branch_id', $branch->getKey())
            ->get();

        DB::transaction(function () use ($branch, $articles): void {
            foreach ($articles as $article) {
                if (is_string($article->featured_image_path)) {
                    Storage::disk('public')->delete($article->featured_image_path);
                }

                $article->delete();
            }

            $branch->delete();
        });
    }

    private function hasRepresentatives(Branch $branch): bool
    {
        return $branch->representatives()->withTrashed()->exists();
    }
}
