<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Job CRUD (CONTRACT §7/§8/§13, SPEC §3.4). Mutations run end-to-end through the
 * ['auth','role:editor','org.scope'] group on PostgreSQL 18 (RefreshDatabase). A
 * create lands status=Active (JOB-01 default — the create DTO carries NO status),
 * server-stamps organization_id from the confined author (never the payload), and
 * sets created_by to the acting user. A delete is a SOFT delete (leaf entity, no
 * restrict pre-check) — the row survives in withTrashed(), graceful by construction
 * (302, never a 500). The status enum is compared as an ENUM INSTANCE (the CMS
 * lesson), salary cents stay integers. Web validation is 302 + session errors,
 * never 422 (covered in JobValidationTest).
 *
 * The editor route group carries 'org.scope', so the acting editor (level 1) is
 * CONFINED to its own organization. The update/delete tests route-model-bind {job},
 * which under confinement resolves only a job in the editor's org; those editors +
 * their job fixtures are therefore pinned to ONE shared organization (else the
 * confined binding would 404).
 */

/** An editor confined to the given org (so org.scope can resolve its jobs). */
function jobEditor(Organization $organization): User
{
    return User::factory()->editor()->forOrganization($organization)->create();
}

/** A valid store/update payload for a job in the given org + branch. */
function jobPayload(Organization $organization, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
        'title' => 'Auxiliar Administrativo',
        'description' => 'Apoyo en tareas administrativas de la sucursal.',
        'schedule' => 'Lunes a viernes, 9:00 a 17:00',
        'contact_info' => 'rh@sindicato.example',
        'salary_min_cents' => 1_500_000,
        'salary_max_cents' => 2_000_000,
        'salary_display' => '15,000 - 20,000 MXN',
    ], $overrides);
}

it('creates a job: status defaults to Active, organization server-stamped, created_by the editor (302 + flash)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $editor = jobEditor($organization);

    actingAs($editor)
        ->post(route('admin.jobs.store'), jobPayload($organization, $branch, ['title' => 'Vacante Nueva']))
        ->assertRedirect(route('admin.jobs.index'))
        ->assertStatus(302)
        ->assertSessionHas('success', __('jobs.created'))
        ->assertSessionHasNoErrors();

    $job = JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('title', 'Vacante Nueva')->sole();

    // Enum-cast attribute is an ENUM INSTANCE in assertions, never a string.
    expect($job->status)->toBe(JobStatus::Active)               // JOB-01 default
        ->and($job->organization_id)->toBe($organization->getKey()) // server-stamped
        ->and($job->branch_id)->toBe($branch->getKey())
        ->and($job->created_by)->toBe($editor->getKey());
});

it('persists salary as INTEGER cents, never a float (the cents invariant)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(jobEditor($organization))
        ->post(route('admin.jobs.store'), jobPayload($organization, $branch, [
            'title' => 'Cajero',
            'salary_min_cents' => 1_200_000,
            'salary_max_cents' => 1_800_000,
        ]))
        ->assertRedirect();

    $job = JobPosting::withoutGlobalScope(OrganizationScope::class)->where('title', 'Cajero')->sole();

    expect($job->salary_min_cents)->toBeInt()->toBe(1_200_000)
        ->and($job->salary_max_cents)->toBeInt()->toBe(1_800_000);
});

it('accepts a job with null salary fields (all optional)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(jobEditor($organization))
        ->post(route('admin.jobs.store'), jobPayload($organization, $branch, [
            'title' => 'Voluntariado',
            'salary_min_cents' => null,
            'salary_max_cents' => null,
            'salary_display' => null,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $job = JobPosting::withoutGlobalScope(OrganizationScope::class)->where('title', 'Voluntariado')->sole();

    expect($job->salary_min_cents)->toBeNull()
        ->and($job->salary_max_cents)->toBeNull()
        ->and($job->salary_display)->toBeNull();
});

it('updates a job within the acting org (302 + updated flash), status untouched', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->paused()->create([
        'title' => 'Antes',
    ]);

    actingAs(jobEditor($organization))
        ->put(route('admin.jobs.update', $job), jobPayload($organization, $branch, [
            'title' => 'Después',
            'schedule' => 'Horario actualizado',
        ]))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHas('success', __('jobs.updated'))
        ->assertSessionHasNoErrors();

    expect($job->fresh())
        ->title->toBe('Después')
        ->schedule->toBe('Horario actualizado')
        // update never touches status (the create DTO has no status) — stays Paused.
        ->status->toBe(JobStatus::Paused);
});

it('soft-deletes a job: removed from default queries but withTrashed() finds it (302, never 500)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $editor = jobEditor($organization);
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->create();

    actingAs($editor)
        ->delete(route('admin.jobs.destroy', $job))
        ->assertRedirect()
        ->assertStatus(302) // graceful: a leaf SoftDelete, never a 500
        ->assertSessionHas('success', __('jobs.deleted'))
        ->assertSessionHasNoErrors();

    // SOFT delete: gone from the default (scope-free) query, still present with trashed.
    $this->assertSoftDeleted('job_postings', ['id' => $job->getKey()]);

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->whereKey($job->getKey())->exists())->toBeFalse()
        ->and(JobPosting::withoutGlobalScope(OrganizationScope::class)->withTrashed()->whereKey($job->getKey())->exists())->toBeTrue();
});
