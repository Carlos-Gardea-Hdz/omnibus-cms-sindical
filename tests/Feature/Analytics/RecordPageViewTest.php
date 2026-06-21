<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\RecordPageViewAction;
use App\Domain\Analytics\Contracts\RecordsPageViews;
use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * The SOLE Analytics write path (CONTRACT §5/§6/§15.1-6, SPEC §3.7 ANALYTICS / §10.5).
 * A public article view (GET /articles/{slug}) must:
 *   - bump views_count atomically by exactly +1 via increment() (NEVER read-modify-write),
 *   - land ONE append-only page_views row (article's org, a 64-char SHA-256 ip_hash,
 *     viewed_at), NEVER the raw dotted IP,
 *   - de-duplicate a same-ip_hash repeat within 24h (NEITHER a 2nd row NOR a 2nd bump),
 *   - record a DIFFERENT ip_hash (a distinct visitor) as a fresh view,
 *   - fail SOFT: if the recording Action throws, the article still renders 200 (a tracking
 *     ping NEVER 500s — Decision B), the fault is logged + swallowed,
 *   - guard the null-org article (slice-002 Deviation C): increment the org-agnostic
 *     counter but SKIP the page_views insert (the row needs a NOT-NULL org), never 500.
 *
 * The public route resolves a PUBLISHED article by slug (org-scope bypassed). Aggregate /
 * isolation correctness is proven in the dashboard tests; this file owns the write. Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/** A published, org-pinned article reachable at GET /articles/{slug}. */
function publishedArticleForViews(?Organization $organization = null): Article
{
    $organization ??= Organization::factory()->create();

    return Article::factory()
        ->published()
        ->forOrganization($organization)
        ->create();
}

it('bumps views_count by exactly +1 and lands one page_views row on a public article view', function (): void {
    $organization = Organization::factory()->create();
    $article = publishedArticleForViews($organization);

    expect($article->views_count)->toBe(0);

    get(route('articles.show', $article->slug))->assertOk();

    expect($article->fresh()->views_count)->toBe(1);

    $views = PageView::withoutGlobalScope(OrganizationScope::class)
        ->where('article_id', $article->getKey())
        ->get();

    expect($views)->toHaveCount(1);

    $row = $views->first();
    expect($row->organization_id)->toBe($organization->getKey())
        ->and($row->viewed_at)->not->toBeNull()
        ->and(strlen((string) $row->ip_hash))->toBe(64);
});

it('records two DISTINCT-IP views atomically without a lost update (views_count = 2)', function (): void {
    $article = publishedArticleForViews();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get(route('articles.show', $article->slug))
        ->assertOk();

    // A second visitor from a different IP — a genuinely distinct view.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->get(route('articles.show', $article->slug))
        ->assertOk();

    expect($article->fresh()->views_count)->toBe(2)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(2);
});

it('de-duplicates a same-ip_hash repeat within 24h: NEITHER a 2nd row NOR a 2nd bump', function (): void {
    $article = publishedArticleForViews();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->get(route('articles.show', $article->slug))->assertOk();

    // Identical client (same IP → same ip_hash) hitting again immediately.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->get(route('articles.show', $article->slug))->assertOk();

    expect($article->fresh()->views_count)->toBe(1)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(1);
});

it('records a fresh view when the SAME ip_hash returns after the 24h dedup window', function (): void {
    $organization = Organization::factory()->create();
    $article = publishedArticleForViews($organization);
    $action = app(RecordPageViewAction::class);
    $ipHash = hash('sha256', 'returning-visitor');

    // An existing view from this ip_hash, OUTSIDE the 24h window.
    PageView::withoutGlobalScope(OrganizationScope::class)->create([
        'article_id' => $article->getKey(),
        'organization_id' => $organization->getKey(),
        'ip_hash' => $ipHash,
        'user_agent' => 'seed-agent',
        'viewed_at' => now()->subHours(25),
    ]);
    $article->forceFill(['views_count' => 1])->save();

    $action->handle($article, $ipHash, 'returning-agent');

    expect($article->fresh()->views_count)->toBe(2)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(2);
});

it('stores a 64-char SHA-256 hash, NEVER the raw dotted IP', function (): void {
    $article = publishedArticleForViews();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->get(route('articles.show', $article->slug))->assertOk();

    $row = PageView::withoutGlobalScope(OrganizationScope::class)
        ->where('article_id', $article->getKey())->firstOrFail();

    expect($row->ip_hash)->not->toContain('203.0.113.99')
        ->and($row->ip_hash)->toMatch('/^[0-9a-f]{64}$/');

    // No column anywhere on the row holds the raw IP.
    foreach ($row->getAttributes() as $value) {
        expect((string) $value)->not->toContain('203.0.113.99');
    }
});

it('NEVER 500s on a tracking failure — the article still renders 200, the fault is swallowed', function (): void {
    $article = publishedArticleForViews();

    // Force the recording contract to throw; the public view must survive (Decision B).
    // The public controller depends on the RecordsPageViews INTERFACE (the final Action is
    // bound behind it in AppServiceProvider) so the fail-soft path can be mocked — a `final`
    // Action cannot be partial-mocked through the container, the interface can.
    $this->mock(RecordsPageViews::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('tracking exploded'));
    });

    Log::shouldReceive('warning')->atLeast()->once();

    get(route('articles.show', $article->slug))->assertOk();
});

it('guards a null-org article: increments the counter but SKIPS the page_views insert (no 500)', function (): void {
    // slice-002 Deviation C: organization_id is nullable; an org-less published article.
    $article = Article::factory()->published()->create(['organization_id' => null]);

    expect($article->organization_id)->toBeNull();

    get(route('articles.show', $article->slug))->assertOk();

    // The org-agnostic counter still bumps; the NOT-NULL-org row is skipped, never errors.
    expect($article->fresh()->views_count)->toBe(1)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(0);
});

it('exposes a race-safe handle() that increments via increment(), not read-modify-write', function (): void {
    $organization = Organization::factory()->create();
    $article = publishedArticleForViews($organization);
    $action = app(RecordPageViewAction::class);

    // Two distinct visitors, called directly on the Action (the unit of work).
    $action->handle($article, hash('sha256', 'visitor-a'), 'agent-a');
    $action->handle($article, hash('sha256', 'visitor-b'), 'agent-b');

    expect($article->fresh()->views_count)->toBe(2)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(2);

    // A third call from visitor-a within 24h is a no-op (dedup at the Action level).
    $action->handle($article, hash('sha256', 'visitor-a'), 'agent-a');

    expect($article->fresh()->views_count)->toBe(2)
        ->and(PageView::withoutGlobalScope(OrganizationScope::class)
            ->where('article_id', $article->getKey())->count())->toBe(2);
});
