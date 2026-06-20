<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Exceptions\InvalidArticleTransitionException;
use App\Domain\Content\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Unpublish an article — the published→draft edge (Deviation B, SPEC §3.3
 * NEWS-07 superset). The ArticleStatus graph is the authoritative guard: an
 * article not in Published cannot be unpublished and throws
 * InvalidArticleTransitionException. On success the status returns to Draft and
 * published_at is cleared, inside a transaction.
 */
final class UnpublishArticleAction
{
    public function handle(Article $article): Article
    {
        if (! $article->status->canTransitionTo(ArticleStatus::Draft)) {
            throw InvalidArticleTransitionException::between($article->status, ArticleStatus::Draft);
        }

        return DB::transaction(function () use ($article): Article {
            $article->fill([
                'status' => ArticleStatus::Draft,
                'published_at' => null,
            ])->save();

            return $article;
        });
    }
}
