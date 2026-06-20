<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Http\Controllers\Controller;
use App\Support\OrganizationScope;
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
 * safe. `views_count` increment / page-view tracking is DEFERRED to the Analytics slice.
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
    public function show(string $article): Response
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
