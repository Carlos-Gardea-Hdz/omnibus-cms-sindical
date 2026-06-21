<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\TimePeriod;
use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;

/**
 * ArticleAnalyticsService (SPEC §3.7 ANALYTICS-02/03) — the read-only article metric
 * service. Mirrors the UNIGES TerminalEfficiencyService: each public method is ONE grouped
 * aggregate query, every Postgres aggregate (which arrives as `mixed` via the magic
 * accessor) is guarded through toInt() before casting (PHPStan L10), and it NEVER calls
 * withoutGlobalScope — the global OrganizationScope on Article/PageView IS the org
 * isolation (a confined administrator aggregates only their own org; super_admin is
 * unconfined). The optional $organizationId filter actively narrows an UNCONFINED
 * super_admin's view to one org; for a confined administrator it is a harmless extra
 * predicate equal to the scope's own constraint.
 *
 * No write, no transaction, Illuminate\Http-free. date_trunc units come from the
 * TimePeriod enum (no magic strings) and are bound as parameters.
 */
final class ArticleAnalyticsService
{
    /** The scoped sum of views_count across the viewer's articles. */
    public function totalViews(?int $organizationId = null): int
    {
        return $this->toInt(
            Article::query()
                ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
                ->sum('views_count')
        );
    }

    /**
     * The top-N articles by views_count (DESC) within the viewer's scope.
     *
     * @return list<array{id:int,title:string,views_count:int}>
     */
    public function topArticlesByViews(int $limit = 5, ?int $organizationId = null): array
    {
        $articles = Article::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderByDesc('views_count')
            ->limit($limit)
            ->get(['id', 'title', 'views_count']);

        $result = [];

        foreach ($articles as $article) {
            $result[] = [
                'id' => $this->toInt($article->id),
                'title' => $this->toStringValue($article->title),
                'views_count' => $this->toInt($article->views_count),
            ];
        }

        return $result;
    }

    /**
     * The page-view count per date_trunc bucket (ONE grouped query over the scoped
     * PageView). The branch/category filters select the page-view's article's branch/
     * category via a single join. Every bucket total is mixed-cast-guarded — a 0-row
     * window simply does not appear (the dashboard treats absent as 0); a present bucket
     * is never null.
     *
     * @return list<array{bucket:string,total:int}>
     */
    public function viewsOverTime(
        TimePeriod $period,
        ?int $articleId = null,
        ?int $branchId = null,
        ?int $categoryId = null,
        ?int $organizationId = null,
    ): array {
        $needsArticleJoin = $branchId !== null || $categoryId !== null;

        $rows = PageView::query()
            ->when($organizationId !== null, fn ($q) => $q->where('page_views.organization_id', $organizationId))
            ->when($articleId !== null, fn ($q) => $q->where('page_views.article_id', $articleId))
            ->when($needsArticleJoin, fn ($q) => $q->join('articles', 'articles.id', '=', 'page_views.article_id'))
            ->when($branchId !== null, fn ($q) => $q->where('articles.branch_id', $branchId))
            ->when($categoryId !== null, fn ($q) => $q->where('articles.category_id', $categoryId))
            ->selectRaw('date_trunc(?, page_views.viewed_at)::date as bucket', [$period->truncUnit()])
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'bucket' => $this->toStringValue($row->getAttribute('bucket')),
                'total' => $this->toInt($row->getAttribute('total')),
            ];
        }

        return $result;
    }

    /**
     * Postgres aggregates (count(*), sum(), date_trunc) arrive as `mixed` via the magic
     * attribute accessor; guard before casting (PHPStan L10 — never `(int)` on raw mixed).
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toStringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
