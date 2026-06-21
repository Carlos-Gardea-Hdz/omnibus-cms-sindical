<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * The admin Analytics dashboard read model (CONTRACT §7-§10/§15.7-12, SPEC §3.7 / §10.2).
 * The dashboard is READ-ONLY: every metric is ONE grouped aggregate query (no per-row
 * loop, no N+1), the Postgres aggregates come back MIXED and are guarded by the toInt
 * cast (a 0-row bucket is 0, NEVER null), and the numbers respect org isolation. This
 * file owns:
 *   - aggregate CORRECTNESS for a fixed seeded scenario (total_views, top_articles,
 *     jobs.active/closed, the over-time / trend buckets),
 *   - the single-query / no-N+1 assertion (DB::listen counts the grouped queries),
 *   - the exact snake_case Inertia prop contract (§10),
 *   - PII handling (the props carry NO individual ip_hash / user_agent — only aggregates).
 *
 * Role gating and the org-isolation crown live in their own files. The acting user here
 * is a super_admin (unconfined) so the aggregates span every seeded org deterministically.
 * Runs on PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/**
 * A fixed, deterministic analytics scenario inside ONE organization.
 *
 * @return array{org: Organization, branch: Branch, topArticle: Article}
 */
function seedAnalyticsScenario(): array
{
    $org = Organization::factory()->create(['name' => 'Sindicato Demo']);
    $branch = Branch::factory()->for($org)->create();

    // Three articles with KNOWN views_count: 30, 10, 5 (DESC top order is deterministic).
    $top = Article::factory()->published()->forBranch($branch)->create([
        'title' => 'Más Visto',
        'views_count' => 30,
    ]);
    Article::factory()->published()->forBranch($branch)->create([
        'title' => 'Segundo',
        'views_count' => 10,
    ]);
    Article::factory()->published()->forBranch($branch)->create([
        'title' => 'Tercero',
        'views_count' => 5,
    ]);
    // total views_count for the org = 45.

    // Page-view events in a single month bucket (the over-time series).
    PageView::factory()->count(4)->forArticle($top)->viewedAt(now()->startOfMonth()->addDays(2))->create();

    // Jobs: 2 active, 1 closed (the active-vs-closed mini-stat).
    JobPosting::factory()->count(2)->forBranch($branch)->create(['status' => JobStatus::Active]);
    JobPosting::factory()->forBranch($branch)->create(['status' => JobStatus::Closed]);

    // Members + contact messages (the registration / message trends).
    Member::factory()->count(3)->forOrganization($org)->create();
    ContactMessage::factory()->count(2)->forBranch($branch)->create();

    return compact('org', 'branch') + ['topArticle' => $top];
}

it('computes total_views as the scoped sum(views_count) for the seeded scenario', function (): void {
    seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Analytics/Index')
                ->where('metrics.total_views', 45),
        );
});

it('ranks top_articles by views_count DESC (deterministic 30/10/5 order)', function (): void {
    $scenario = seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.top_articles.0.id', $scenario['topArticle']->getKey())
                ->where('metrics.top_articles.0.title', 'Más Visto')
                ->where('metrics.top_articles.0.views_count', 30)
                ->where('metrics.top_articles.1.views_count', 10)
                ->where('metrics.top_articles.2.views_count', 5),
        );
});

it('computes jobs.active and jobs.closed via a scoped filtered count (2 active, 1 closed)', function (): void {
    seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.jobs.active', 2)
                ->where('metrics.jobs.closed', 1),
        );
});

it('aggregates the over-time / trend buckets as integer counts, never null (the mixed-cast guard)', function (): void {
    seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.views_over_time', function (Collection $buckets): bool {
                    $buckets->each(fn (array $b) => expect($b['total'])->toBeInt());

                    return (int) $buckets->sum('total') === 4;
                })
                ->where('metrics.member_trend', function (Collection $buckets): bool {
                    $buckets->each(fn (array $b) => expect($b['total'])->toBeInt());

                    return (int) $buckets->sum('total') === 3;
                })
                ->where('metrics.contact_trend', function (Collection $buckets): bool {
                    $buckets->each(fn (array $b) => expect($b['total'])->toBeInt());

                    return (int) $buckets->sum('total') === 2;
                }),
        );
});

it('runs the dashboard with a small fixed query count regardless of row volume (no N+1, one grouped query per metric)', function (): void {
    // A LARGE scenario: if any metric loops per-row, the query count explodes.
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();
    $article = Article::factory()->published()->forBranch($branch)->create(['views_count' => 50]);
    PageView::factory()->count(40)->forArticle($article)->create();
    JobPosting::factory()->count(20)->forBranch($branch)->create(['status' => JobStatus::Active]);
    Member::factory()->count(25)->forOrganization($org)->create();
    ContactMessage::factory()->count(15)->forBranch($branch)->create();

    $admin = User::factory()->superAdmin()->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    actingAs($admin)->get(route('admin.analytics.index'))->assertOk();

    // SELECT-only aggregate queries (ignore the session/auth bookkeeping writes).
    $aggregateSelects = array_filter(
        $queries,
        fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select')
            && (str_contains(strtolower($sql), 'page_views')
                || str_contains(strtolower($sql), 'job_postings')
                || str_contains(strtolower($sql), 'members')
                || str_contains(strtolower($sql), 'contact_messages')
                || str_contains(strtolower($sql), 'articles')),
    );

    // ~6 metrics, each ONE grouped/aggregate query (+ a tiny options lookup margin).
    // A per-row loop over 40+ events would push this into the dozens — this FAILS it.
    expect(count($aggregateSelects))->toBeLessThanOrEqual(12);
});

it('locks the exact snake_case Admin/Analytics/Index prop contract (§10)', function (): void {
    seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Analytics/Index')
                // metrics
                ->has('metrics.total_views')
                ->has('metrics.top_articles')
                ->hasAll([
                    'metrics.top_articles.0.id',
                    'metrics.top_articles.0.title',
                    'metrics.top_articles.0.views_count',
                ])
                ->has('metrics.views_over_time')
                ->hasAll([
                    'metrics.views_over_time.0.bucket',
                    'metrics.views_over_time.0.total',
                ])
                ->has('metrics.jobs.active')
                ->has('metrics.jobs.closed')
                ->has('metrics.member_trend')
                ->has('metrics.contact_trend')
                // filters
                ->has('filters.organization_id')
                ->has('filters.branch_id')
                ->has('filters.category_id')
                ->where('filters.period', 'month')
                // options
                ->has('options.organizations')
                ->has('options.branches')
                ->has('options.categories'),
        );
});

it('honours the period filter, defaulting to month and accepting day/week', function (string $period): void {
    seedAnalyticsScenario();

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index', ['period' => $period]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('filters.period', $period),
        );
})->with([
    'day' => ['day'],
    'week' => ['week'],
    'month' => ['month'],
]);

it('surfaces ONLY aggregates — never an individual ip_hash or user_agent in any prop (PII)', function (): void {
    $scenario = seedAnalyticsScenario();
    // A page_view with a recognisably unique, PII-shaped payload.
    PageView::factory()->forArticle($scenario['topArticle'])->create([
        'ip_hash' => hash('sha256', 'pii-canary-ip'),
        'user_agent' => 'PII-CANARY-AGENT/1.0',
    ]);

    // Request with the Inertia headers so the body is the raw JSON page (props inline).
    // The matching X-Inertia-Version is sent so the request passes Inertia's asset-version
    // reconciliation whether or not a local Vite manifest exists — a bare X-Inertia request
    // with a stale/empty version triggers a 409 (force-reload), not a 200 with inline props.
    $version = app(App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    $response = actingAs(User::factory()->superAdmin()->create())
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', (string) $version)
        ->get(route('admin.analytics.index'))
        ->assertOk();

    // The serialised page must not leak the row-level PII through ANY prop.
    $payload = $response->getContent();
    expect($payload)->not->toContain('PII-CANARY-AGENT')
        ->and($payload)->not->toContain(hash('sha256', 'pii-canary-ip'))
        ->and(strtolower((string) $payload))->not->toContain('ip_hash')
        ->and(strtolower((string) $payload))->not->toContain('user_agent');
});
