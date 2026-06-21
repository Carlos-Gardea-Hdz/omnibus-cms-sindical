<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE ORG-ISOLATION CROWN — symmetric, falsifiable tenant confinement for the Analytics
 * dashboard (CONTRACT §7/§10/§15.10, SPEC §3.7 ANALYTICS-02/03 / §10.2 / §11.2). The
 * §10.2 matrix gives an administrator OWN-ORG analytics and a super_admin ALL orgs.
 *
 * The crux: an administrator's dashboard MUST report ONLY its own org's numbers
 * (total_views / top articles / job counts), and a super_admin's MUST span every org and
 * narrow to a single org when ?organization_id= is supplied.
 *
 *   FALSIFIABLE: the all-orgs sum(views_count) — read via
 *   Article::withoutGlobalScope(OrganizationScope::class)->sum('views_count') — STRICTLY
 *   EXCEEDS the administrator's visible total_views. The cross-org rows EXIST; the
 *   dashboard hides them. A naive controller that aggregates every org for an
 *   administrator goes RED here.
 *
 * NOTE on the mechanism (the §0 tension surfaced during implement): EnsureOrganizationScope
 * UNCONFINES administrator + super_admin, so the global OrganizationScope does NOT auto-
 * confine an administrator. The own-org confinement therefore rides the §7 explicit
 * organization_id narrowing the AnalyticsController applies for a non-super_admin actor.
 * This test pins the OBSERVABLE contract (administrator sees only own org) regardless of
 * which mechanism delivers it. Runs on PostgreSQL 18 (RefreshDatabase). FICTIONAL fixtures.
 */

/**
 * One fully-populated organization: a branch, three articles with a known views_count
 * total, two active + one closed job.
 *
 * @return array{org: Organization, branch: Branch, viewsTotal: int}
 */
function isolatedAnalyticsOrg(string $name, int $baseViews): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $branch = Branch::factory()->for($org)->create();

    Article::factory()->published()->forBranch($branch)->create(['views_count' => $baseViews]);
    Article::factory()->published()->forBranch($branch)->create(['views_count' => $baseViews + 5]);
    $viewsTotal = $baseViews + ($baseViews + 5);

    JobPosting::factory()->count(2)->forBranch($branch)->create(['status' => JobStatus::Active]);
    JobPosting::factory()->forBranch($branch)->create(['status' => JobStatus::Closed]);

    return compact('org', 'branch', 'viewsTotal');
}

it('confines an administrator to ONLY its own organization analytics (falsifiable vs the all-orgs physical sum)', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 20); // viewsTotal = 45
    $b = isolatedAnalyticsOrg('Org B', 100); // viewsTotal = 205

    $admin = User::factory()->administrator()->forOrganization($a['org'])->create();

    actingAs($admin)
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Analytics/Index')
                ->where('metrics.total_views', $a['viewsTotal']) // only A's 45
                ->where('metrics.jobs.active', 2)  // A's jobs only
                ->where('metrics.jobs.closed', 1),
        );

    // FALSIFIABLE: the all-orgs sum physically EXCEEDS what the admin saw.
    $allOrgsSum = (int) Article::withoutGlobalScope(OrganizationScope::class)->sum('views_count');
    expect($allOrgsSum)->toBe($a['viewsTotal'] + $b['viewsTotal'])
        ->toBeGreaterThan($a['viewsTotal']);
});

it('ignores a hostile ?organization_id / ?branch_id from an administrator (cannot pivot to another org)', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 20);  // viewsTotal = 45
    $b = isolatedAnalyticsOrg('Org B', 100); // viewsTotal = 205

    $admin = User::factory()->administrator()->forOrganization($a['org'])->create();

    // THE EXACT ATTACK the §7 explicit-org fix closes: an administrator of A asking for B's
    // metrics + B's branch. The controller must force organization_id back to A and never
    // leak B's metrics OR B's branch metadata into A's option list.
    actingAs($admin)
        ->get(route('admin.analytics.index', [
            'organization_id' => $b['org']->getKey(),
            'branch_id' => $b['branch']->getKey(),
        ]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.total_views', $a['viewsTotal'])         // STILL A's 45, never B's 205
                ->where('filters.organization_id', $a['org']->getKey())  // forced back to A
                ->where('options.branches', function (Collection $branches) use ($a, $b): bool {
                    expect($branches->pluck('id'))
                        ->toContain($a['branch']->getKey())
                        ->not->toContain($b['branch']->getKey());         // B's branch must not leak

                    return true;
                }),
        );
});

it('never leaks a cross-org top article into the administrator dashboard', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 10);
    $b = isolatedAnalyticsOrg('Org B', 500); // B's articles dwarf A's

    // B's most-viewed article id — it must NEVER appear in A's admin top list.
    $bTopId = Article::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->orderByDesc('views_count')->firstOrFail()->getKey();

    $admin = User::factory()->administrator()->forOrganization($a['org'])->create();

    actingAs($admin)
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.top_articles', function (Collection $articles) use ($bTopId): bool {
                    expect($articles->pluck('id'))->not->toContain($bTopId);

                    return true;
                }),
        );
});

it('confines an administrators page_view over-time series to its own org (cross-org events are invisible, not absent)', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 10);
    $b = isolatedAnalyticsOrg('Org B', 10);

    // 3 page-view events for A, 7 for B — all in the current month bucket.
    $aArticle = Article::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $a['org']->getKey())->firstOrFail();
    $bArticle = Article::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $b['org']->getKey())->firstOrFail();
    PageView::factory()->count(3)->forArticle($aArticle)->viewedAt(now()->startOfMonth()->addDay())->create();
    PageView::factory()->count(7)->forArticle($bArticle)->viewedAt(now()->startOfMonth()->addDay())->create();

    $admin = User::factory()->administrator()->forOrganization($a['org'])->create();

    actingAs($admin)
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.views_over_time', function (Collection $buckets): bool {
                    return (int) $buckets->sum('total') === 3; // only A's 3 events
                }),
        );

    // FALSIFIABLE: B's 7 events physically exist, hidden from A's administrator.
    expect(PageView::withoutGlobalScope(OrganizationScope::class)->count())->toBe(10);
});

it('lets a super_admin see EVERY organizations analytics (unconfined)', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 20); // 45
    $b = isolatedAnalyticsOrg('Org B', 30); // 65

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.total_views', $a['viewsTotal'] + $b['viewsTotal']) // 110
                ->where('metrics.jobs.active', 4) // 2 + 2
                ->where('metrics.jobs.closed', 2), // 1 + 1
        );
});

it('narrows the super_admin view to a single org when ?organization_id= is supplied', function (): void {
    $a = isolatedAnalyticsOrg('Org A', 20); // 45
    isolatedAnalyticsOrg('Org B', 30); // 65

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index', ['organization_id' => $a['org']->getKey()]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('metrics.total_views', $a['viewsTotal']) // narrowed to A's 45
                ->where('filters.organization_id', $a['org']->getKey()),
        );
});
