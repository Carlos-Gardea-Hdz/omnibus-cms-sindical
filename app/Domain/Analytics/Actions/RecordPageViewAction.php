<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Contracts\RecordsPageViews;
use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * RecordPageViewAction (SPEC §3.7 ANALYTICS-01, NEWS-04) — the SOLE Analytics write path:
 * a CHEAP, race-safe, deduped recording of one public article view. Called from
 * Public\ArticleController@show (Decision C — the controller already resolves the exact
 * published article + its org, so no double lookup; the controller wraps the call in a
 * fail-soft try/catch — Decision B — so a tracking fault NEVER 500s the article render).
 *
 * Illuminate\Http-free: the ip_hash and user_agent arrive as plain scalars (the raw IP is
 * SHA-256-hashed at the edge by the controller and never reaches here — §10.5). The
 * organization is server-derived from the article (no payload — Decision A).
 *
 * The record is:
 *   1. a 24h dedup EXISTS check (ONE indexed query on [article_id, ip_hash, viewed_at]) —
 *      if this ip_hash already viewed this article within 24h it is a NO-OP (NEITHER the
 *      increment NOR the insert fire — both effects are gated together);
 *   2. else, in ONE DB::transaction: an ATOMIC `views_count` bump via increment()
 *      (UPDATE … views_count = views_count + 1 — race-safe, NEVER read-modify-write) AND a
 *      single PageView insert.
 *
 * NULL-ORG GUARD: Article.organization_id is int|null (slice-002 Deviation C). page_views
 * .organization_id is NOT NULL, so a null-org article would violate the constraint. In that
 * case we STILL increment views_count (the column bump is org-agnostic) but SKIP the
 * page_views insert — a null-org article never 500s the insert. The public `show` serves
 * only PUBLISHED articles (which in practice carry an org), so the guard is belt-and-braces.
 *
 * Returns void (a tracking ping has no response body). MAY throw a real DB fault — the
 * caller swallows it; the dedup/increment tests call handle() directly.
 */
final class RecordPageViewAction implements RecordsPageViews
{
    private const DEDUP_HOURS = 24;

    public function handle(Article $article, string $ipHash, ?string $userAgent): void
    {
        $alreadyViewed = PageView::query()
            ->where('article_id', $article->id)
            ->where('ip_hash', $ipHash)
            ->where('viewed_at', '>=', now()->subHours(self::DEDUP_HOURS))
            ->exists();

        if ($alreadyViewed) {
            return; // no-op — neither the increment nor the insert fire (dedup, both effects gated together).
        }

        DB::transaction(function () use ($article, $ipHash, $userAgent): void {
            // Atomic UPDATE … views_count = views_count + 1 — race-safe (NEVER read-modify-write).
            $article->increment('views_count');

            // NULL-ORG GUARD: page_views.organization_id is NOT NULL — a null-org article
            // bumps the (org-agnostic) counter but records NO page_views row.
            if ($article->organization_id === null) {
                return;
            }

            PageView::query()->create([
                'article_id' => $article->id,
                'organization_id' => $article->organization_id, // server-derived from the article (no payload).
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'viewed_at' => now(),
            ]);
        });
    }
}
