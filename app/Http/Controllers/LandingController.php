<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Support\OrganizationScope;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public union-CMS home (SPEC §8.3 / Decision G). The landing surfaces the latest
 * PUBLISHED articles + the latest ACTIVE job postings across EVERY organization — the
 * public site is cross-org, so both reads bypass the {@see OrganizationScope} explicitly
 * (a published article / active job is visible to ANY visitor, even a logged-in editor
 * whose session is org-confined). Mirrors the public Article/Job controllers' bypass.
 *
 * The STATUS filters are NOT optional (the same falsifiable invariant the public
 * single-view paths enforce): articles are constrained to {@see ArticleStatus::Published}
 * + a non-null `published_at`, and jobs to {@see JobStatus::Active}, so a DRAFT / Archived
 * article or a Draft / Paused / Closed job — of ANY org — NEVER appears on the home page.
 *
 * Anemic + no N+1: two bounded, eager-loaded queries shaped to the exact snake_case props
 * the `Landing/Index` React page consumes (`latestArticles`: id/title/subtitle/slug/
 * published_at; `latestJobs`: id/title/salary_display). No per-row DB work.
 */
final class LandingController
{
    /** How many cards the home page surfaces per section. */
    private const LATEST_LIMIT = 6;

    public function __invoke(): Response
    {
        return Inertia::render('Landing/Index', [
            'latestArticles' => $this->latestArticles(),
            'latestJobs' => $this->latestJobs(),
        ]);
    }

    /**
     * The latest published articles, cross-org (public). A Draft / Archived row — or one
     * with a null published_at — is excluded, so an unpublished article never leaks home.
     *
     * @return list<array{id: int, title: string, subtitle: string|null, slug: string, published_at: string|null}>
     */
    private function latestArticles(): array
    {
        return array_values(
            Article::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('status', ArticleStatus::Published)
                ->whereNotNull('published_at')
                ->latest('published_at')
                ->limit(self::LATEST_LIMIT)
                ->get(['id', 'title', 'subtitle', 'slug', 'published_at'])
                ->map(fn (Article $article): array => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'subtitle' => $article->subtitle,
                    'slug' => $article->slug,
                    'published_at' => $article->published_at?->toIso8601String(),
                ])
                ->all()
        );
    }

    /**
     * The latest active job postings, cross-org (public). A Draft / Paused / Closed row is
     * excluded, so a non-active job never leaks onto the home page.
     *
     * @return list<array{id: int, title: string, salary_display: string|null}>
     */
    private function latestJobs(): array
    {
        return array_values(
            JobPosting::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('status', JobStatus::Active)
                ->latest('created_at')
                ->limit(self::LATEST_LIMIT)
                ->get(['id', 'title', 'salary_display'])
                ->map(fn (JobPosting $job): array => [
                    'id' => $job->id,
                    'title' => $job->title,
                    'salary_display' => $job->salary_display,
                ])
                ->all()
        );
    }
}
