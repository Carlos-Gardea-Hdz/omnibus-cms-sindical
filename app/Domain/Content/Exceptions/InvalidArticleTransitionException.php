<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Enums\ArticleStatus;
use RuntimeException;

/**
 * An article status transition was rejected by the ArticleStatus state graph
 * (SPEC §3.3 NEWS-07). Carries only a translated message so the Content domain
 * stays free of Illuminate\Http — the HTTP render (302 + `status` field error on
 * web, 422 JSON on API) lives in bootstrap/app.php. The illegal pair is captured
 * in the message via the between() named constructor.
 */
final class InvalidArticleTransitionException extends RuntimeException
{
    public static function between(ArticleStatus $from, ArticleStatus $to): self
    {
        return new self(__('articles.error.invalid_transition', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }
}
