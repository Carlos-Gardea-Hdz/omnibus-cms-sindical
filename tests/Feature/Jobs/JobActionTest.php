<?php

declare(strict_types=1);

use App\Domain\Jobs\Actions\CreateJobPostingAction;
use App\Domain\Jobs\Actions\DeleteJobPostingAction;
use App\Domain\Jobs\Actions\ToggleJobStatusAction;
use App\Domain\Jobs\Actions\UpdateJobPostingAction;
use App\Domain\Jobs\Data\CreateJobPostingData;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Exceptions\InvalidJobTransitionException;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * Action-level unit tests (CONTRACT §7/§13, SPEC §11.5). Each Action is exercised
 * directly (Arrange-Act-Assert) with the OrganizationContext set explicitly, so the
 * server-stamp / cross-org-branch / transition-guard invariants are proven WITHOUT
 * the HTTP layer. The salary-cents integer invariant (SPEC §2.2: money is integer
 * cents, never float) is pinned on the persisted row. Runs on PostgreSQL 18.
 */

/** Build a CreateJobPostingData from an array of overrides over a valid baseline. */
function jobData(Organization $organization, Branch $branch, array $overrides = []): CreateJobPostingData
{
    return CreateJobPostingData::from(array_merge([
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
        'title' => 'Action Vacante',
        'description' => 'Descripción de la vacante.',
        'schedule' => 'Completo',
        'contact_info' => 'rh@example.com',
        'salary_min_cents' => 1_000_000,
        'salary_max_cents' => 2_000_000,
        'salary_display' => '10,000 - 20,000',
    ], $overrides));
}

/** Resolve an action with the context confined to (or unconfined for) an org. */
function confinedContext(?int $organizationId): OrganizationContext
{
    $context = app(OrganizationContext::class);
    $context->confineTo($organizationId);

    return $context;
}

it('CreateJobPostingAction stamps the confined org, defaults to Active, and persists integer cents', function (): void {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();
    $actor = User::factory()->editor()->forOrganization($org)->create();

    confinedContext($org->getKey());
    $action = app(CreateJobPostingAction::class);

    // Confined caller forges a DIFFERENT org in the payload — the Action must ignore it.
    $foreign = Organization::factory()->create();
    $job = $action->handle(jobData($foreign, $branch, ['title' => 'Stamped']), $actor);

    expect($job->organization_id)->toBe($org->getKey()) // server-stamped, not the payload's foreign org
        ->and($job->created_by)->toBe($actor->getKey())
        ->and($job->status)->toBe(JobStatus::Active)     // JOB-01 default
        ->and($job->salary_min_cents)->toBeInt()->toBe(1_000_000)
        ->and($job->salary_max_cents)->toBeInt()->toBe(2_000_000);
});

it('CreateJobPostingAction honours a payload org for an UNCONFINED caller', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $branchB = Branch::factory()->for($orgB)->create();
    $actor = User::factory()->superAdmin()->create();

    app(OrganizationContext::class)->unconfine();
    $job = app(CreateJobPostingAction::class)->handle(jobData($orgB, $branchB), $actor);

    expect($job->organization_id)->toBe($orgB->getKey()); // payload honoured when unconfined
});

it('CreateJobPostingAction rejects a branch that belongs to a different org (cross-org FK)', function (): void {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $foreignBranch = Branch::factory()->for($other)->create();
    $actor = User::factory()->manager()->forOrganization($org)->create();

    confinedContext($org->getKey());

    expect(fn (): JobPosting => app(CreateJobPostingAction::class)
        ->handle(jobData($org, $foreignBranch), $actor))
        ->toThrow(ValidationException::class);

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

it('UpdateJobPostingAction edits writable fields but never moves the job out of the confined org', function (): void {
    $org = Organization::factory()->create();
    $branch = Branch::factory()->for($org)->create();
    $foreign = Organization::factory()->create();
    $job = JobPosting::factory()->forOrganization($org)->forBranch($branch)->create([
        'title' => 'Old',
        'status' => JobStatus::Paused,
    ]);

    confinedContext($org->getKey());
    $updated = app(UpdateJobPostingAction::class)->handle(
        $job,
        jobData($foreign, $branch, ['title' => 'New Title']), // forged foreign org in payload
    );

    expect($updated->title)->toBe('New Title')
        ->and($updated->organization_id)->toBe($org->getKey()) // never moved tenants
        ->and($updated->fresh()->status)->toBe(JobStatus::Paused); // status not in the create DTO
});

it('ToggleJobStatusAction flips a legal transition', function (): void {
    $org = Organization::factory()->create();
    $job = JobPosting::factory()->forOrganization($org)->create(['status' => JobStatus::Active]);

    $result = app(ToggleJobStatusAction::class)->handle($job, JobStatus::Paused);

    expect($result->fresh()->status)->toBe(JobStatus::Paused);
});

it('ToggleJobStatusAction throws InvalidJobTransitionException on an illegal transition, leaving the row untouched', function (): void {
    $org = Organization::factory()->create();
    $job = JobPosting::factory()->forOrganization($org)->create(['status' => JobStatus::Closed]);

    expect(fn (): JobPosting => app(ToggleJobStatusAction::class)->handle($job, JobStatus::Active))
        ->toThrow(InvalidJobTransitionException::class);

    expect($job->fresh()->status)->toBe(JobStatus::Closed); // terminal, untouched
});

it('DeleteJobPostingAction soft-deletes the job (leaf entity, graceful by construction)', function (): void {
    $org = Organization::factory()->create();
    $job = JobPosting::factory()->forOrganization($org)->create();

    app(DeleteJobPostingAction::class)->handle($job);

    $this->assertSoftDeleted('job_postings', ['id' => $job->getKey()]);
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->withTrashed()->whereKey($job->getKey())->exists())->toBeTrue();
});
