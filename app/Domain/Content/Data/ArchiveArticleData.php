<?php

declare(strict_types=1);

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\ArticleStatus;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transition payload for the `archive` route (SPEC §3.3 NEWS-07, Deviation B). The
 * route serves the lifecycle edges that leave Published WITHOUT a precondition: a
 * bodyless POST archives (published → archived), while an explicit
 * `status` of `draft` unpublishes (published → draft). The target defaults to
 * Archived so the common "archive this" call carries no body. The strict legality
 * check (and the published_at side-effect) stays in the Actions / ArticleStatus
 * graph — this DTO only resolves the requested target, never authorises it.
 */
#[TypeScript]
final class ArchiveArticleData extends Data
{
    public function __construct(
        // This route serves ONLY the precondition-free edges (archive + unpublish).
        // Reject `published` here so it can't bypass PublishArticleAction's
        // featured-image / non-empty-content gate (NEWS-07).
        #[In(['draft', 'archived'])]
        public ArticleStatus $status = ArticleStatus::Archived,
    ) {}
}
