<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Analytics\Contracts\RecordsPageViews;
use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Http\Controllers\Controller;
use App\Support\OrganizationScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public single-article view (SPEC §3.3 NEWS-04). Resolved by `slug`
 * (`/articles/{article}`, the `{article}` segment IS the slug) and rendered with the exact snake_case prop contract
 * the `Articles/Show` page expects. Anemic by law: it shapes the read model and
 * renders — no business logic. The page renders the sanitized TipTap `content`
 * through a structural renderer that emits React elements from a whitelisted node/mark
 * tree (auto-escaping all text, never dangerouslySetInnerHTML over user HTML) — a
 * client-side mirror of the server allow-list, defense-in-depth. The authoritative
 * stored-XSS defense already ran on the way IN
 * ({@see \App\Domain\Content\Services\SanitizesContent}), so the DB row is already
 * safe.
 *
 * SLICE-007 (Analytics, NEWS-04): after the article resolves, a page view is RECORDED
 * via {@see RecordsPageViews} (the final RecordPageViewAction behind its contract) — an
 * atomic `views_count` bump + an append-only
 * `page_views` row, deduped per (article, ip-hash) over 24h. The recording is wrapped in
 * a fail-SOFT try/catch (Decision B): a tracking fault NEVER 500s the page — the article
 * still renders 200, the failure is logged and swallowed. The raw IP is NEVER stored: it
 * is hashed (SHA-256, APP_KEY-salted) HERE in the controller, so the Action receives a
 * plain `string $ipHash` and stays Http-/PII-free (§10.5). The `request()` HELPER is used
 * (not the `Illuminate\Http\Request` TYPE — the arch rule forbids only the type-hint).
 *
 * SLICE-003: this is PUBLIC content, so it bypasses the {@see OrganizationScope}
 * explicitly — the article is resolved by slug WITHOUT the global org scope. The
 * org-scope is an admin-shell concern (it confines a manager/editor's authoring views);
 * a published article must be visible to ANY visitor regardless of whether the request
 * happens to carry a confined session (a logged-in editor browsing the public site).
 * The binding is therefore done manually here, not via implicit (scoped) route binding.
 *
 * The org bypass is intentional but the STATUS filter is NOT optional: the lookup is
 * constrained to {@see ArticleStatus::Published} (+ a non-null published_at) so a Draft
 * or Archived article — of ANY org — 404s for an anonymous visitor instead of leaking a
 * 200 by slug. Public == published, never every-status.
 */
final class ArticleController extends Controller
{
    public function show(string $article, RecordsPageViews $record): Response
    {
        $article = Article::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('slug', $article)
            // The public route serves PUBLISHED content only: a Draft / Archived row
            // (of ANY org) must 404 to an anonymous visitor, never leak a 200 by slug.
            ->where('status', ArticleStatus::Published)
            ->whereNotNull('published_at')
            ->with(['category:id,name', 'author:id,name'])
            ->firstOrFail();

        // Page-view tracking (NEWS-04, Decision B/C): fail-SOFT — never 500 the page on a
        // tracking fault. The raw IP is hashed here (APP_KEY-salted, dedup-stable) so it is
        // NEVER stored and the Action stays Http-/PII-free (§10.5).
        try {
            $record->handle(
                $article,
                hash('sha256', (string) request()->ip().config()->string('app.key')),
                substr((string) request()->userAgent(), 0, 255),
            );
        } catch (\Throwable $e) {
            Log::warning('page_view tracking failed', [
                'article_id' => $article->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return Inertia::render('Articles/Show', [
            'article' => [
                'title' => $article->title,
                'subtitle' => $article->subtitle,
                'content' => $article->content,
                'signature' => $article->signature,
                'featured_image_url' => $article->featured_image_path === null
                    ? null
                    : Storage::disk('public')->url($article->featured_image_path),
                // category & author are NOT NULL restrict FKs (eager-loaded above).
                'category_name' => $article->category->name,
                'author_name' => $article->author->name,
                'published_at' => $article->published_at?->toIso8601String(),
            ],
        ]);
    }
}
