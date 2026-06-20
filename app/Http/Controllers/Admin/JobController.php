<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Jobs\Actions\CreateJobPostingAction;
use App\Domain\Jobs\Actions\DeleteJobPostingAction;
use App\Domain\Jobs\Actions\ToggleJobStatusAction;
use App\Domain\Jobs\Actions\UpdateJobPostingAction;
use App\Domain\Jobs\Data\CreateJobPostingData;
use App\Domain\Jobs\Data\ToggleJobStatusData;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job-posting CRUD + lifecycle (SPEC §3.4 JOB-01/02, §6.3.10, §7.2, §10.2 / slice-004 §8).
 * JobPosting is org-scoped: the listing AND the branch picker are auto-filtered by the
 * {@see \App\Support\OrganizationScope} global scope under the `org.scope` middleware — a
 * confined manager/editor sees ONLY their own org's jobs and branches (no manual `where`),
 * and a cross-org route-model-bound row is unresolvable → 404. Gated `role:editor` upstream
 * in routes/web.php (the WHOLE Jobs lifecycle — including the status toggle — is editor-scoped,
 * unlike Article publish; JOB-02).
 *
 * Anemic by law (≤15 lines/method): each mutation hands a validated
 * {@see CreateJobPostingData}/{@see ToggleJobStatusData} (resolved via the method signature →
 * web failure is 302 + session errors, never 422) to its Action, which owns the write, the
 * SERVER-SIDE org-id stamp for a confined caller (the DTO's `organization_id` is never trusted
 * for a manager — it is read from the request-scoped {@see OrganizationContext}), the cross-org
 * branch assertion, and the {@see JobStatus} transition guard. The actor is read through the
 * `Auth` facade — `Illuminate\Http\Request` is never imported (controller arch law); the index
 * status filter uses the `request()` helper.
 *
 * The unconfined admin/super_admin additionally gets `organization_options` on the create/edit
 * forms (an org PICKER, mirroring the Representative form), since the Action honours a payload
 * `organization_id` only when {@see OrganizationContext::isUnconfined()}.
 */
final class JobController extends Controller
{
    public function index(): Response
    {
        $status = request()->string('status')->toString() ?: null;

        $jobs = JobPosting::query()
            ->with(['branch:id,name', 'creator:id,name'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (JobPosting $job): array => $this->mapRow($job));

        return Inertia::render('Admin/Jobs/Index', [
            'jobs' => [
                'data' => $jobs->items(),
                'links' => $jobs->linkCollection()->toArray(),
                'meta' => [
                    'from' => $jobs->firstItem(),
                    'to' => $jobs->lastItem(),
                    'total' => $jobs->total(),
                ],
            ],
            'branch_options' => $this->branchOptions(),
            'statuses' => $this->statusOptions(),
            'filters' => ['status' => $status],
        ]);
    }

    public function create(OrganizationContext $context): Response
    {
        return Inertia::render('Admin/Jobs/Create', array_filter([
            'branch_options' => $this->branchOptions(),
            'organization_options' => $context->isUnconfined() ? $this->organizationOptions() : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function store(CreateJobPostingData $data, CreateJobPostingAction $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $action->handle($data, $actor);

        return redirect()->route('admin.jobs.index')->with('success', __('jobs.created'));
    }

    public function edit(JobPosting $job, OrganizationContext $context): Response
    {
        return Inertia::render('Admin/Jobs/Edit', array_filter([
            'job' => [
                'id' => $job->id,
                'title' => $job->title,
                'description' => $job->description,
                'schedule' => $job->schedule,
                'contact_info' => $job->contact_info,
                'branch_id' => $job->branch_id,
                'organization_id' => $job->organization_id,
                'salary_min_cents' => $job->salary_min_cents,
                'salary_max_cents' => $job->salary_max_cents,
                'salary_display' => $job->salary_display,
                'status' => $job->status->value,
            ],
            'branch_options' => $this->branchOptions(),
            'organization_options' => $context->isUnconfined() ? $this->organizationOptions() : null,
            'statuses' => $this->statusOptions(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function update(JobPosting $job, CreateJobPostingData $data, UpdateJobPostingAction $action): RedirectResponse
    {
        $action->handle($job, $data);

        return redirect()->route('admin.jobs.index')->with('success', __('jobs.updated'));
    }

    public function destroy(JobPosting $job, DeleteJobPostingAction $action): RedirectResponse
    {
        $action->handle($job);

        return redirect()->route('admin.jobs.index')->with('success', __('jobs.deleted'));
    }

    public function toggleStatus(JobPosting $job, ToggleJobStatusData $data, ToggleJobStatusAction $action): RedirectResponse
    {
        $action->handle($job, $data->status);

        return back()->with('success', __('jobs.status_changed'));
    }

    /**
     * Shape one paginated job row for the admin index (snake_case prop contract).
     *
     * @return array<string, mixed>
     */
    private function mapRow(JobPosting $job): array
    {
        // branch is a NOT NULL restrict FK (eager-loaded above).
        return [
            'id' => $job->id,
            'title' => $job->title,
            'branch_name' => $job->branch->name,
            'status' => $job->status->value,
            'status_label_key' => $job->status->labelKey(),
            'salary_display' => $job->salary_display,
            // created_at is non-nullable (timestamps always set) — no nullsafe needed (L9).
            'created_at' => $job->created_at->toIso8601String(),
        ];
    }

    /**
     * The branch select options for the index filter + forms (id + name). Org-scoped, so a
     * confined editor/manager only ever sees their own org's branches.
     *
     * @return list<array{id: int, name: string}>
     */
    private function branchOptions(): array
    {
        return array_values(
            Branch::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ])->all()
        );
    }

    /**
     * The organization select options for the unconfined admin's create/edit form (id + name).
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
     * The JobStatus select options for the filter + lifecycle toggle (value + i18n key).
     *
     * @return list<array{value: string, label_key: string}>
     */
    private function statusOptions(): array
    {
        return array_values(array_map(
            static fn (JobStatus $status): array => [
                'value' => $status->value,
                'label_key' => $status->labelKey(),
            ],
            JobStatus::cases(),
        ));
    }
}
