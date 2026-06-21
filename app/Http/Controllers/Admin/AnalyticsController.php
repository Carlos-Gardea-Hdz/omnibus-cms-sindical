<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\Data\AnalyticsFilterData;
use App\Domain\Analytics\Enums\TimePeriod;
use App\Domain\Analytics\Services\ArticleAnalyticsService;
use App\Domain\Analytics\Services\EngagementAnalyticsService;
use App\Domain\Content\Models\Category;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin analytics dashboard (SPEC §3.7 ANALYTICS-01/02/03, §6.3.13-14, §7.5, §10.2-10.3,
 * §10.5 / slice-007 §8). Gated `['auth','role:administrator','org.scope']` upstream in
 * routes/web.php (§10.2 RBAC matrix: administrator = own-org, super_admin = all; an editor
 * or a MANAGER hitting this is a 403 — a LOWER rung than administrator).
 *
 * READ-ONLY by law: there is exactly ONE method (index) and ZERO mutations — the only
 * Analytics write is the public page-view recording on the article-view path
 * ({@see \App\Http\Controllers\Public\ArticleController}). `/admin/analytics/export` is
 * DEFERRED (§7.5, slice-007 "Out of scope") — no method, no route.
 *
 * Anemic + aggregate-only: each metric is ONE grouped query delegated to a read-only
 * metric Service ({@see ArticleAnalyticsService}, {@see EngagementAnalyticsService}) — no
 * per-row work in the controller (slice-007 §7, the no-N+1 discipline).
 *
 * ORG ISOLATION (§10.2, the load-bearing rule): `EnsureOrganizationScope` UNCONFINES BOTH
 * administrator AND super_admin, so the global OrganizationScope does NOT auto-confine an
 * administrator's metric reads. The own-org confinement therefore rides an EXPLICIT
 * organization_id this controller threads into EVERY metric Service:
 *   - super_admin  → unconfined; the optional `?organization_id=` filter narrows to one org
 *                    (null = every org), and the cross-org picker is offered.
 *   - administrator → FORCED to its OWN `organization_id` regardless of any filter — it can
 *                    NEVER aggregate another org's metrics, and never sees the org picker.
 * The actor's role/org is read via the `request()->user()` HELPER (not the
 * `Illuminate\Http\Request` TYPE — the arch rule forbids only the type-hint).
 *
 * PII boundary (§10.5): the props carry ONLY aggregates — never an `ip_hash` or a
 * `user_agent`. The dashboard surfaces totals, top-5 titles and bucketed counts.
 */
final class AnalyticsController extends Controller
{
    public function index(
        AnalyticsFilterData $filters,
        ArticleAnalyticsService $articles,
        EngagementAnalyticsService $engagement,
    ): Response {
        $period = $filters->period ?? TimePeriod::default();

        $actor = request()->user();
        $isSuperAdmin = $actor?->role === UserRole::SuperAdmin;

        // ORG-ISOLATION SPINE (SPEC §10.2): the global OrganizationScope UNCONFINES BOTH
        // super_admin AND administrator (EnsureOrganizationScope), so the metric reads do
        // NOT auto-confine an administrator. The own-org confinement therefore rides an
        // EXPLICIT organization_id the controller threads into every metric Service:
        //   - super_admin  → unconfined; the optional ?organization_id= filter narrows to
        //                    one org (null = every org).
        //   - administrator → FORCED to its OWN organization_id, regardless of any filter —
        //                    it can NEVER aggregate another org's metrics (the §10.2 own-org
        //                    rule, the falsifiable AnalyticsOrgIsolationTest crown).
        $organizationId = $isSuperAdmin
            ? $filters->organization_id
            : $actor?->organization_id;

        return Inertia::render('Admin/Analytics/Index', [
            'metrics' => [
                'total_views' => $articles->totalViews($organizationId),
                'top_articles' => $articles->topArticlesByViews(5, $organizationId),
                'views_over_time' => $articles->viewsOverTime(
                    $period,
                    // No article-level filter is exposed on the dashboard (the DTO / §10
                    // filter set is org/branch/category/period only) — always null here.
                    null,
                    $filters->branch_id,
                    $filters->category_id,
                    $organizationId,
                ),
                'jobs' => $engagement->jobsActiveVsClosed($organizationId),
                'member_trend' => $engagement->memberRegistrationTrend($period, $organizationId),
                'contact_trend' => $engagement->contactMessagesPerPeriod($period, $organizationId),
            ],
            'filters' => [
                'organization_id' => $organizationId,
                'branch_id' => $filters->branch_id,
                'category_id' => $filters->category_id,
                'period' => $period->value,
            ],
            'options' => [
                // The cross-org picker is offered ONLY to a super_admin (the sole actor
                // that may switch orgs). An administrator is pinned to its own org and never
                // sees the selector; a confined lower actor never reaches this route.
                'organizations' => $isSuperAdmin ? $this->organizationOptions() : [],
                'branches' => $this->branchOptions($organizationId),
                'categories' => $this->categoryOptions(),
            ],
        ]);
    }

    /**
     * Cross-org organization picker (unconfined actor only). NOT org-scoped on Organization
     * itself; offered only when {@see OrganizationContext::isUnconfined()}.
     *
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        return array_values(
            Organization::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ])->all()
        );
    }

    /**
     * Branch filter options, confined to the resolved org. EnsureOrganizationScope UNCONFINES
     * an administrator, so the global OrganizationScope on Branch is a no-op for this request —
     * we must filter EXPLICITLY by the threaded $organizationId (mirroring the metric services),
     * or an administrator would receive every org's branch names/ids (a cross-org metadata leak).
     * A super_admin with no org filter ($organizationId === null) legitimately sees all branches.
     *
     * @return list<array{id: int, name: string}>
     */
    private function branchOptions(?int $organizationId): array
    {
        return array_values(
            Branch::query()
                ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ])->all()
        );
    }

    /**
     * Category filter options (the shared content catalog). Category is NOT org-scoped — it
     * is a global taxonomy, so every actor sees the same list.
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return array_values(
            Category::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->all()
        );
    }
}
