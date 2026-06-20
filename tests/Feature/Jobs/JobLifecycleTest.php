<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Job lifecycle state machine, end-to-end (CONTRACT §7/§8/§13, SPEC §3.4). The
 * toggle sits behind ['auth','role:editor','org.scope'] (JOB-02 is editor-scoped,
 * NOT manager-gated — unlike Article publish). ToggleJobStatusAction enforces the
 * JobStatus transition graph: a legal move flips the status (302 + flash); an illegal
 * move throws InvalidJobTransitionException, rendered to a 302 + 'status' session
 * error by bootstrap/app.php — NEVER a 500, and the row is untouched. The acting
 * editor is CONFINED to the job's org (else the route-model binding 404s). Runs on
 * PostgreSQL 18 (RefreshDatabase).
 */

/** An editor confined to an org with a job in a known starting status. */
function lifecycleSetup(JobStatus $status): array
{
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->create([
        'status' => $status,
    ]);

    return compact('editor', 'job');
}

it('performs every legal toggle: the status flips and a success flash is set (302)', function (JobStatus $from, JobStatus $to): void {
    ['editor' => $editor, 'job' => $job] = lifecycleSetup($from);

    actingAs($editor)
        ->post(route('admin.jobs.status', $job), ['status' => $to->value])
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHas('success', __('jobs.status_changed'))
        ->assertSessionHasNoErrors();

    // Enum-cast attribute compared as an ENUM INSTANCE.
    expect($job->fresh()->status)->toBe($to);
})->with([
    'draft → active' => [JobStatus::Draft, JobStatus::Active],
    'draft → closed' => [JobStatus::Draft, JobStatus::Closed],
    'active → paused' => [JobStatus::Active, JobStatus::Paused],
    'active → closed' => [JobStatus::Active, JobStatus::Closed],
    'paused → active' => [JobStatus::Paused, JobStatus::Active],
    'paused → closed' => [JobStatus::Paused, JobStatus::Closed],
]);

it('rejects an illegal toggle with a 302 + status error, leaving the status UNCHANGED, NEVER a 500', function (JobStatus $from, JobStatus $to): void {
    ['editor' => $editor, 'job' => $job] = lifecycleSetup($from);

    actingAs($editor)
        ->post(route('admin.jobs.status', $job), ['status' => $to->value])
        ->assertRedirect()
        ->assertStatus(302) // the enum guard → InvalidJobTransitionException → 302, not 500
        ->assertSessionHasErrors('status');

    // The guard rejected the move: the row is untouched.
    expect($job->fresh()->status)->toBe($from);
})->with([
    'closed → active (terminal, cannot reopen)' => [JobStatus::Closed, JobStatus::Active],
    'closed → draft (terminal)' => [JobStatus::Closed, JobStatus::Draft],
    'closed → paused (terminal)' => [JobStatus::Closed, JobStatus::Paused],
    'active → draft (cannot reopen to draft)' => [JobStatus::Active, JobStatus::Draft],
    'paused → draft (cannot reopen to draft)' => [JobStatus::Paused, JobStatus::Draft],
    'draft → paused (must activate first)' => [JobStatus::Draft, JobStatus::Paused],
]);

it('rejects a self-toggle (e.g. active → active) as illegal: 302 + status error, no 500', function (): void {
    ['editor' => $editor, 'job' => $job] = lifecycleSetup(JobStatus::Active);

    actingAs($editor)
        ->post(route('admin.jobs.status', $job), ['status' => JobStatus::Active->value])
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('status');

    expect($job->fresh()->status)->toBe(JobStatus::Active);
});

it('rejects a bad enum value in the toggle payload as a 302 validation error, never a 500', function (): void {
    ['editor' => $editor, 'job' => $job] = lifecycleSetup(JobStatus::Active);

    actingAs($editor)
        ->post(route('admin.jobs.status', $job), ['status' => 'expired'])
        ->assertRedirect()
        ->assertStatus(302) // enum coercion fails in the DTO → 302, not 422/500
        ->assertSessionHasErrors('status');

    expect($job->fresh()->status)->toBe(JobStatus::Active);
});
