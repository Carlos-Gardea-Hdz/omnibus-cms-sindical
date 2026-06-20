<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE CROWN — symmetric, falsifiable org confinement for the Jobs domain
 * (CONTRACT §13, SPEC §3.4 / §10.2 / §11.2; the slice-003 tenant-write lesson). The
 * CMS analogue of OrganizationScopeIsolationTest for job_postings. Given jobs across
 * org A and org B:
 *
 *   READ  — a MANAGER of A listing /admin/jobs sees ONLY A's jobs (never B's); editing
 *           an org-B job 404s (the global OrganizationScope makes the cross-org row
 *           unresolvable); a SUPER_ADMIN sees ALL orgs' jobs. FALSIFIABLE:
 *           JobPosting::withoutGlobalScope(OrganizationScope)->count() PHYSICALLY
 *           EXCEEDS the manager's visible count — the rows exist, the scope hides them.
 *
 *   WRITE — a global SELECT scope does NOT constrain INSERT/UPDATE column values, so the
 *           Create/Update Actions MUST server-stamp organization_id from the confined
 *           caller and reject any cross-org branch_id. These blocks (A)/(B)/(C) prove
 *           the guard closes that hole; they go RED against a naive impl that wrote
 *           $data->organization_id straight from the payload. A super_admin is unconfined
 *           and may honour a payload organization_id (but never a mismatched branch).
 *
 * Confinement is set by the 'org.scope' middleware from the acting user's role
 * (manager confines; super_admin unconfines). Runs on PostgreSQL 18 (RefreshDatabase).
 */

/**
 * One fully-populated org: a manager, a branch, and a job created by that manager.
 *
 * @return array{org: Organization, manager: User, branch: Branch, job: JobPosting}
 */
function isolatedJobOrg(string $name): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $manager = User::factory()->manager()->forOrganization($org)->create();
    $branch = Branch::factory()->for($org)->create();
    $job = JobPosting::factory()->forOrganization($org)->forBranch($branch)->createdBy($manager)->create();

    return compact('org', 'manager', 'branch', 'job');
}

/** A baseline cross-org store payload (org A author, fields valid). */
function isolationJobPayload(Organization $organization, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
        'title' => 'Isolation Vacante',
        'description' => 'Descripción de prueba de aislamiento.',
        'schedule' => 'Completo',
        'contact_info' => 'rh@example.com',
    ], $overrides);
}

/*
 * ─────────────────────────── READ confinement ───────────────────────────
 */

it('confines a manager listing /admin/jobs to ONLY its own organization (falsifiable vs physical count)', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Jobs/Index')
                ->has('jobs.data', 1) // only A's single job
                ->where('jobs.data.0.id', $a['job']->getKey()),
        );

    // FALSIFIABLE: 2 jobs physically exist, the manager saw exactly 1.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2)->toBeGreaterThan(1);
});

it('404s a manager of org A trying to edit an org-B job (cross-org row is unresolvable)', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.jobs.edit', $b['job']))
        ->assertNotFound();

    // The org-B job is physically intact.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($b['job']->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('404s a manager of org A trying to delete an org-B job, leaving it untouched', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    actingAs($a['manager'])
        ->delete(route('admin.jobs.destroy', $b['job']))
        ->assertNotFound();

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($b['job']->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('lets a super_admin see ALL organizations jobs (unconfined)', function (): void {
    isolatedJobOrg('Org A');
    isolatedJobOrg('Org B');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Jobs/Index')
                ->has('jobs.data', 2), // A + B both visible
        );
});

/*
 * ─────────────────────────── WRITE confinement (THE CROWN) ───────────────────────────
 * The SELECT scope does NOT constrain INSERT/UPDATE column values; the Create/Update
 * Actions must. These go RED against a naive impl that wrote $data->organization_id
 * straight from the payload (mirrors OrganizationScopeIsolationTest blocks A/B/C).
 */

it('(A) refuses a confined manager planting a job into another org via organization_id', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    // Manager of A POSTs a NEW job with organization_id forged to B (and even B's branch).
    actingAs($a['manager'])
        ->from(route('admin.jobs.create'))
        ->post(route('admin.jobs.store'), isolationJobPayload($b['org'], $a['branch'], [
            'title' => 'Forged Vacante',
        ]))
        ->assertRedirect();

    // No job landed in org B; the one created (if any) belongs to A — never the forged org.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('title', 'Forged Vacante')
        ->exists())->toBeFalse();

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $a['org']->getKey())
        ->where('title', 'Forged Vacante')
        ->exists())->toBeTrue();
});

it('(B) refuses a confined manager MOVING its own job to another org via update', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    // Manager of A PUTs ITS OWN job with organization_id forged to B.
    actingAs($a['manager'])
        ->from(route('admin.jobs.edit', $a['job']))
        ->put(route('admin.jobs.update', $a['job']), isolationJobPayload($b['org'], $a['branch'], [
            'title' => 'Renamed Vacante',
        ]))
        ->assertRedirect();

    // The job stayed in A — never moved out of the tenant.
    expect($a['job']->refresh()->organization_id)->toBe($a['org']->getKey());

    // Falsifiable: no job named 'Renamed Vacante' physically sits in org B.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('title', 'Renamed Vacante')
        ->exists())->toBeFalse();
});

it('(C) refuses a confined manager attaching a job to another org branch (cross-org FK)', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    $before = JobPosting::withoutGlobalScope(OrganizationScope::class)->count();

    // Manager of A honestly claims its own org but points branch_id at org B's branch.
    actingAs($a['manager'])
        ->from(route('admin.jobs.create'))
        ->post(route('admin.jobs.store'), isolationJobPayload($a['org'], $b['branch'], [
            'title' => 'CrossBranch Vacante',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('branch_id');

    // No new job landed anywhere — the count is unchanged and the specific row never exists.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->count())->toBe($before);
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('title', 'CrossBranch Vacante')->exists())->toBeFalse();
});

it('lets an UNCONFINED super_admin legitimately set the organization + a matching branch via the payload', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.jobs.create'))
        ->post(route('admin.jobs.store'), isolationJobPayload($b['org'], $b['branch'], [
            'title' => 'Admin Choice Vacante',
        ]))
        ->assertRedirect(route('admin.jobs.index'))
        ->assertSessionHasNoErrors();

    // The payload-supplied org is honoured for an unconfined admin.
    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('title', 'Admin Choice Vacante')
        ->exists())->toBeTrue();
});

it('refuses an UNCONFINED super_admin pairing org A with a branch from a DIFFERENT org', function (): void {
    $a = isolatedJobOrg('Org A');
    $b = isolatedJobOrg('Org B');

    // Admin chooses org A but a branch that belongs to org B → the invariant is violated.
    actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.jobs.create'))
        ->post(route('admin.jobs.store'), isolationJobPayload($a['org'], $b['branch'], [
            'title' => 'Wrong Pair Vacante',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('branch_id');

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)
        ->where('title', 'Wrong Pair Vacante')->exists())->toBeFalse();
});
