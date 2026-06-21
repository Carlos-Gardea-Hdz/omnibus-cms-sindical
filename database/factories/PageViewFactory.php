<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageView>
 *
 * FICTIONAL data ONLY (PII rules). `ip_hash` is a SHA-256 hash of a FAKER ipv4 — never a
 * real IP, and never the raw dotted IP (the column only ever holds a hash, §10.5). The
 * default article is created via Article::factory() and the page-view's organization_id
 * is pinned to that SAME article's org (a consistent pair — the view always belongs to its
 * article's org). `user_agent` is a faker UA truncated to 255 (the column width).
 */
final class PageViewFactory extends Factory
{
    protected $model = PageView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A single shared organization anchors BOTH the article and this page-view, so the
        // view's org always matches its article's org (the consistent pair). The factories
        // are resolved lazily as attribute values (the codebase convention — never an eager
        // ->create() inside definition()); the article is published + pinned to that org.
        $organization = Organization::factory();

        /** @var string $userAgent */
        $userAgent = fake()->userAgent();

        return [
            'article_id' => Article::factory()->published()->for($organization),
            'organization_id' => $organization,
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'viewed_at' => fake()->dateTimeBetween('-60 days', 'now'),
        ];
    }

    /** Pin the view to an existing article (and its organization — the pair stays consistent). */
    public function forArticle(Article $article): static
    {
        return $this->state(fn (array $attributes): array => [
            'article_id' => $article->getKey(),
            'organization_id' => $article->organization_id,
        ]);
    }

    /** Pin the view's event clock to a specific moment. */
    public function viewedAt(CarbonInterface $viewedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'viewed_at' => $viewedAt,
        ]);
    }

    /**
     * A dedup clash: the SAME article_id + ip_hash as $other, viewed within the 24h window
     * (one hour after $other) — for asserting the 24h dedup gate.
     */
    public function dedupClash(PageView $other): static
    {
        return $this->state(fn (array $attributes): array => [
            'article_id' => $other->article_id,
            'organization_id' => $other->organization_id,
            'ip_hash' => $other->ip_hash,
            'viewed_at' => $other->viewed_at->copy()->addHour(),
        ]);
    }
}
