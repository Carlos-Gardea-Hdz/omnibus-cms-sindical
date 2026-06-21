<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Contracts;

use App\Domain\Content\Models\Article;

/**
 * The page-view recording contract (SPEC §3.7 ANALYTICS-01, NEWS-04). The sole Analytics
 * write path is fronted by this interface so the public article-view controller depends on
 * an ABSTRACTION, not the concrete final Action — keeping the {@see \App\Domain\Analytics\
 * Actions\RecordPageViewAction} `final` (the arch `domain code is final` rule) while still
 * letting the fail-soft contract be swapped/mocked in tests (a `final` class cannot be
 * partial-mocked through the container; an interface can). Bound concrete → interface in
 * AppServiceProvider.
 *
 * Illuminate\Http-free: the ip_hash and user_agent arrive as plain scalars (the raw IP is
 * SHA-256-hashed at the controller edge and never reaches the implementation — §10.5); the
 * organization is server-derived from the article. Returns void (a tracking ping has no
 * body) and MAY throw a real DB fault — the caller swallows it (Decision B).
 */
interface RecordsPageViews
{
    public function handle(Article $article, string $ipHash, ?string $userAgent): void;
}
