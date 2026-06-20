<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * THE CROWN — the PUBLIC job board serves ACTIVE postings ONLY, across all orgs
 * (CONTRACT §8/§13, SPEC §3.4; the slice-003 public-Article BLOCKER). The public
 * routes resolve jobs WITHOUT the OrganizationScope (a job board is org-agnostic by
 * design) BUT are constrained to JobStatus::Active — so a Draft / Paused / Closed
 * posting of ANY org 404s for a visitor instead of leaking by id, and never leaks a
 * draft/expired listing. Public == active, never every-status. A logged-in confined
 * editor still sees ALL active jobs (the unconfined path). Runs on PostgreSQL 18.
 */

/** A job of the given status pinned to a fresh org + branch. */
function publicJob(JobStatus $status, string $title): JobPosting
{
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();

    return JobPosting::factory()->forOrganization($org)->forBranch($branch)->create([
        'status' => $status,
        'title' => $title,
    ]);
}

it('lists ONLY active jobs on /jobs across every org, never a draft/paused/closed one', function (): void {
    $activeA = publicJob(JobStatus::Active, 'Active A');
    $activeB = publicJob(JobStatus::Active, 'Active B');
    publicJob(JobStatus::Draft, 'Hidden Draft');
    publicJob(JobStatus::Paused, 'Hidden Paused');
    publicJob(JobStatus::Closed, 'Hidden Closed');

    get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                ->has('jobs.data', 2) // exactly the two active jobs (both orgs)
                ->where('jobs.data.0.title', fn (string $t): bool => in_array($t, ['Active A', 'Active B'], true))
                ->where('jobs.data.1.title', fn (string $t): bool => in_array($t, ['Active A', 'Active B'], true)),
        );
});

it('orders the public board by created_at DESC (newest active first)', function (): void {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();

    $older = JobPosting::factory()->forOrganization($org)->forBranch($branch)->active()->create([
        'title' => 'Older Active',
        'created_at' => now()->subDays(3),
    ]);
    $newer = JobPosting::factory()->forOrganization($org)->forBranch($branch)->active()->create([
        'title' => 'Newer Active',
        'created_at' => now()->subDay(),
    ]);

    get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                ->has('jobs.data', 2)
                ->where('jobs.data.0.id', $newer->getKey())  // newest first
                ->where('jobs.data.1.id', $older->getKey()),
        );
});

it('serves an ACTIVE job 200 on /jobs/{id} (the happy path)', function (): void {
    $job = publicJob(JobStatus::Active, 'Live Vacante');

    get(route('jobs.show', $job->getKey()))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Show')
                ->where('job.title', 'Live Vacante'),
        );
});

it('404s a visitor requesting a DRAFT/PAUSED/CLOSED job by id (any org)', function (JobStatus $status): void {
    $job = publicJob($status, "Hidden {$status->value}");

    get(route('jobs.show', $job->getKey()))->assertNotFound();
})->with([
    'draft' => [JobStatus::Draft],
    'paused' => [JobStatus::Paused],
    'closed' => [JobStatus::Closed],
]);

it('lets an org-confined logged-in editor still see ALL active jobs on /jobs (unconfined path)', function (): void {
    // The editor is confined to one org; the public board must NOT shrink to that org.
    $editorOrg = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($editorOrg)->create();

    publicJob(JobStatus::Active, 'Active Other Org 1');
    publicJob(JobStatus::Active, 'Active Other Org 2');

    actingAs($editor)
        ->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                // sees BOTH other-org active jobs, not confined to its own — and the
                // org-scoped branch relation renders (a NULL branch would 500, not 200).
                ->has('jobs.data', 2)
                ->has('jobs.data.0.branch_name')
                ->has('jobs.data.1.branch_name'),
        );
});

it('serves an org-confined viewer the public SHOW of another org active job (branch renders, no 500)', function (): void {
    // A manager confined to org A loads the PUBLIC show for an ACTIVE org-B job. Branch
    // IS org-scoped, so a scoped eager-load would resolve NULL → 500. The public path must
    // be fully unconfined: 200 + the branch name rendered.
    $confinedOrg = Organization::factory()->create();
    $manager = User::factory()->manager()->forOrganization($confinedOrg)->create();

    $otherOrg = Organization::factory()->create();
    $otherBranch = Branch::factory()->for($otherOrg)->create(['name' => 'Sucursal Foránea']);
    $job = JobPosting::factory()->forOrganization($otherOrg)->forBranch($otherBranch)->create([
        'status' => JobStatus::Active,
        'title' => 'Active Other Org Vacante',
    ]);

    actingAs($manager)
        ->get(route('jobs.show', $job->getKey()))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Show')
                ->where('job.title', 'Active Other Org Vacante')
                ->where('job.branch_name', 'Sucursal Foránea'),
        );
});
