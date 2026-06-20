<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Soft-delete an article (SPEC §3.3 NEWS-03). The row is soft-deleted
 * (recoverable); the article_images rows cascade only at a physical (hard) delete
 * via the DB FK, so they survive a soft delete by design. The physical
 * featured-image file is removed from storage after the soft delete commits, so
 * a removed article leaves no orphaned image bytes.
 */
final class DeleteArticleAction
{
    public function handle(Article $article): void
    {
        $imagePath = $article->featured_image_path;

        DB::transaction(static fn (): ?bool => $article->delete());

        if (is_string($imagePath) && Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
    }
}
