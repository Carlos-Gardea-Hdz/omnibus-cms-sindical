<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Http\Controllers\Controller;
use App\Support\OrganizationScope;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public job board (SPEC §3.4 JOB-03 / slice-004 §8). The listing and the single view are
 * PUBLIC, so they bypass the {@see OrganizationScope} explicitly — a job is resolved WITHOUT
 * the global org scope. The org-scope is an admin-shell concern (it confines an editor/manager's
 * authoring views); an active job must be visible to ANY visitor regardless of whether the
 * request happens to carry a confined session (a logged-in editor browsing the public site).
 * The single-view binding is therefore done MANUALLY here (a string `{job}` param), not via
 * implicit (scoped) route-model binding.
 *
 * The org bypass is intentional but the STATUS filter is NOT optional: every lookup is
 * constrained to {@see JobStatus::Active} so a draft / paused / closed job — of ANY org — 404s
 * (single view) or is simply absent (board) for a visitor, never leaking a 200 by id. Public ==
 * active, never every-status. `status`, `organization_id` and `created_by` are NEVER exposed in
 * the public prop shape.
 */
final class JobController extends Controller
{
    public function index(): Response
    {
        $jobs = JobPosting::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('status', JobStatus::Active)
            // The public board is FULLY unconfined: branch IS org-scoped, so for a
            // logged-in CONFINED viewer browsing another org's active job the eager-loaded
            // relation would resolve to NULL under the global scope → 500. Strip the scope
            // off the relation too (mirrors Public\ArticleController). Organization is
            // unscoped already; stripped here as well to be safe.
            ->with(['branch' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->select('id', 'name')])
            ->latest('created_at')
            ->paginate(12)
            ->through(fn (JobPosting $job): array => [
                'id' => $job->id,
                'title' => $job->title,
                // branch is a NOT NULL restrict FK (eager-loaded above).
                'branch_name' => $job->branch->name,
                'schedule' => $job->schedule,
                'salary_display' => $job->salary_display,
                // created_at is non-nullable (timestamps always set) — no nullsafe needed (L9).
                'created_at' => $job->created_at->toIso8601String(),
            ]);

        return Inertia::render('Jobs/Index', [
            'jobs' => [
                'data' => $jobs->items(),
                'links' => $jobs->linkCollection()->toArray(),
                'meta' => [
                    'from' => $jobs->firstItem(),
                    'to' => $jobs->lastItem(),
                    'total' => $jobs->total(),
                ],
            ],
        ]);
    }

    public function show(string $job): Response
    {
        $job = JobPosting::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->whereKey($job)
            // The public route serves ACTIVE jobs only: a draft / paused / closed row
            // (of ANY org) must 404 to a visitor, never leak a 200 by id.
            ->where('status', JobStatus::Active)
            // FULLY unconfined: strip the org scope off the eager-loaded branch (and the
            // already-unscoped organization, defensively) so a CONFINED logged-in viewer
            // browsing another org's active job does not get a NULL relation → 500.
            ->with(['branch' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)->select('id', 'name')])
            ->firstOrFail();

        return Inertia::render('Jobs/Show', [
            'job' => [
                'title' => $job->title,
                'description' => $job->description,
                'schedule' => $job->schedule,
                'contact_info' => $job->contact_info,
                // branch is a NOT NULL restrict FK (eager-loaded above).
                'branch_name' => $job->branch->name,
                'salary_display' => $job->salary_display,
                // created_at is non-nullable (timestamps always set) — no nullsafe needed (L9).
                'created_at' => $job->created_at->toIso8601String(),
            ],
        ]);
    }
}
