<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\CategoryInUseException;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Category (hard delete — categories carry NO SoftDeletes). A category
 * referenced by any article is refused gracefully: the pre-check throws
 * CategoryInUseException (rendered as a 302 + friendly field error in
 * bootstrap/app.php) BEFORE any mutation, so the restrict FK on articles is never
 * tripped and no 500 can reach the user (SPEC §3.3 CAT-02).
 */
final class DeleteCategoryAction
{
    public function handle(Category $category): void
    {
        if ($this->isReferenced($category)) {
            throw new CategoryInUseException(__('categories.error.in_use'));
        }

        DB::transaction(static fn (): ?bool => $category->delete());
    }

    private function isReferenced(Category $category): bool
    {
        // withTrashed(): a soft-deleted article's row still physically holds the
        // category_id FK, so the restrict constraint would trip on it too — the
        // pre-check must see trashed rows for the graceful refusal to be honest.
        return Article::withTrashed()
            ->where('category_id', $category->getKey())
            ->exists();
    }
}
