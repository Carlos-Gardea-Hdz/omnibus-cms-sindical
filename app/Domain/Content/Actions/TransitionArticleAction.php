<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Exceptions\InvalidArticleTransitionException;
use App\Domain\Content\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Generic status transition for the edges that carry no preconditions or side
 * data: archive (draft→archived, published→archived) and republish
 * (archived→published). The ArticleStatus graph is the single authoritative
 * guard — any illegal pair throws InvalidArticleTransitionException, so the
 * controller never has to know the legality matrix. Publish (precondition gates)
 * and unpublish (clears published_at) keep their dedicated Actions; this one
 * covers the remaining edges so the lifecycle is complete.
 *
 * published_at semantics: entering Published stamps it (republish makes the
 * article live again); leaving Published for Archived leaves the historical
 * published_at intact (it WAS published once); the Draft edge is owned by
 * UnpublishArticleAction, never reached here.
 */
final class TransitionArticleAction
{
    public function handle(Article $article, ArticleStatus $target): Article
    {
        if (! $article->status->canTransitionTo($target)) {
            throw InvalidArticleTransitionException::between($article->status, $target);
        }

        return DB::transaction(function () use ($article, $target): Article {
            $attributes = ['status' => $target];

            if ($target === ArticleStatus::Published && $article->published_at === null) {
                $attributes['published_at'] = now();
            }

            $article->fill($attributes)->save();

            return $article;
        });
    }
}
