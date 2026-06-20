<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Content\Models\Article;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public single-article view (SPEC §3.3 NEWS-04). The article is route-model-bound
 * by `slug` (`/articles/{article:slug}`) and rendered with the exact snake_case prop
 * contract the `Articles/Show` page expects. Anemic by law: it shapes the read model
 * and renders — no business logic. The page renders the sanitized TipTap `content`
 * through a structural renderer that emits React elements from a whitelisted node/mark
 * tree (auto-escaping all text, never dangerouslySetInnerHTML over user HTML) — a
 * client-side mirror of the server allow-list, defense-in-depth. The authoritative
 * stored-XSS defense already ran on the way IN
 * ({@see \App\Domain\Content\Services\SanitizesContent}), so the DB row is already
 * safe. `views_count` increment / page-view tracking is DEFERRED to the Analytics slice.
 */
final class ArticleController extends Controller
{
    public function show(Article $article): Response
    {
        $article->loadMissing(['category:id,name', 'author:id,name']);

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
