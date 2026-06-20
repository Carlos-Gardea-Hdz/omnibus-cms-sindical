<?php

declare(strict_types=1);

namespace App\Domain\Content\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Publish payload (SPEC §3.3 NEWS-07). Bodyless: publishing carries no client
 * input. The publish preconditions — a featured image must exist and the content
 * must be non-empty — live in PublishArticleAction because they depend on the
 * already-persisted Article, not on request data. Kept as an explicit DTO so the
 * controller signature stays uniform (DTO → Action → response).
 */
#[TypeScript]
final class PublishArticleData extends Data
{
    public function __construct() {}
}
