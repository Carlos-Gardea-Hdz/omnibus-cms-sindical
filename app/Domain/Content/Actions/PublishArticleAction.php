<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\PublishArticleData;
use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Exceptions\InvalidArticleTransitionException;
use App\Domain\Content\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publish an article (SPEC §3.3 NEWS-01 / NEWS-07). Two precondition gates run
 * before the state-machine guard, both depending on the persisted row (hence not
 * expressible on the DTO):
 *   1. a featured image must exist (publish-requires-image);
 *   2. the content must have a non-empty body (publish-requires-content).
 * Then the ArticleStatus graph authorises the transition into Published; an
 * illegal source state throws InvalidArticleTransitionException. On success the
 * status flips and published_at is stamped, inside a transaction.
 */
final class PublishArticleAction
{
    public function handle(Article $article, PublishArticleData $data): Article
    {
        $this->assertHasFeaturedImage($article);
        $this->assertHasContent($article);

        if (! $article->status->canTransitionTo(ArticleStatus::Published)) {
            throw InvalidArticleTransitionException::between($article->status, ArticleStatus::Published);
        }

        return DB::transaction(function () use ($article): Article {
            $article->fill([
                'status' => ArticleStatus::Published,
                'published_at' => now(),
            ])->save();

            return $article;
        });
    }

    private function assertHasFeaturedImage(Article $article): void
    {
        if (! is_string($article->featured_image_path) || $article->featured_image_path === '') {
            throw ValidationException::withMessages([
                'status' => __('articles.error.publish_requires_image'),
            ]);
        }
    }

    private function assertHasContent(Article $article): void
    {
        $body = $article->content['content'] ?? [];

        if (! is_array($body) || $body === []) {
            throw ValidationException::withMessages([
                'status' => __('articles.error.publish_requires_content'),
            ]);
        }
    }
}
