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
 * Runtime prop-contract tests for the Jobs Inertia pages (CONTRACT §9/§13). Inertia
 * props are untyped at runtime, so the static gates cannot catch a controller that
 * serialises a different shape than the React page consumes. These lock the EXACT
 * snake_case prop shape each page receives — a future controller/page drift fails CI.
 * The admin acting user is super_admin (unconfined) so the index lists every org's
 * rows. The public pages assert the NARROWED read model — and crucially that
 * Jobs/Show never leaks status/organization_id/created_by to a visitor. Runs on
 * PostgreSQL 18 (RefreshDatabase).
 */

/** A super_admin sees every org's rows (unconfined) — the simplest admin fixture. */
function jobPropsAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('locks the Admin/Jobs/Index prop contract (paginated, snake_case JobRow + filters + options)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Index']);
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Centro']);
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->create([
        'title' => 'Vacante Index',
        'status' => JobStatus::Active,
        'salary_display' => '15,000 - 20,000',
    ]);

    actingAs(jobPropsAdmin())
        ->get(route('admin.jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Jobs/Index')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.id', $job->getKey())
                ->where('jobs.data.0.title', 'Vacante Index')
                ->where('jobs.data.0.branch_name', 'Sucursal Centro')
                ->where('jobs.data.0.status', 'active')
                ->where('jobs.data.0.status_label_key', 'job_status.active')
                ->where('jobs.data.0.salary_display', '15,000 - 20,000')
                ->hasAll([
                    'jobs.data.0.id',
                    'jobs.data.0.title',
                    'jobs.data.0.branch_name',
                    'jobs.data.0.status',
                    'jobs.data.0.status_label_key',
                    'jobs.data.0.salary_display',
                    'jobs.data.0.created_at',
                ])
                ->has('jobs.links')
                ->has('jobs.meta')
                ->has('branch_options')
                ->has('statuses')
                ->has('filters'),
        );
});

it('locks the Admin/Jobs/Create prop contract (branch options present)', function (): void {
    $organization = Organization::factory()->create();
    Branch::factory()->for($organization)->create(['name' => 'Sucursal Create']);

    actingAs(jobPropsAdmin())
        ->get(route('admin.jobs.create'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Jobs/Create')
                ->has('branch_options'),
        );
});

it('locks the Admin/Jobs/Edit prop contract (full editable job, snake_case, salary cents + status)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->create([
        'title' => 'Vacante Edit',
        'description' => 'Descripción editable',
        'schedule' => 'Completo',
        'contact_info' => 'rh@example.com',
        'salary_min_cents' => 1_000_000,
        'salary_max_cents' => 2_000_000,
        'salary_display' => '10,000 - 20,000',
        'status' => JobStatus::Paused,
    ]);

    actingAs(jobPropsAdmin())
        ->get(route('admin.jobs.edit', $job))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Jobs/Edit')
                ->where('job.id', $job->getKey())
                ->where('job.title', 'Vacante Edit')
                ->where('job.description', 'Descripción editable')
                ->where('job.schedule', 'Completo')
                ->where('job.contact_info', 'rh@example.com')
                ->where('job.branch_id', $branch->getKey())
                ->where('job.organization_id', $organization->getKey())
                ->where('job.salary_min_cents', 1_000_000)
                ->where('job.salary_max_cents', 2_000_000)
                ->where('job.salary_display', '10,000 - 20,000')
                ->where('job.status', 'paused')
                ->has('branch_options')
                ->has('statuses'),
        );
});

it('locks the public Jobs/Index prop contract (PublicJobRow: title, branch, schedule, salary)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Pública']);
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->active()->create([
        'title' => 'Vacante Pública',
        'schedule' => 'Medio tiempo',
        'salary_display' => '8,000',
    ]);

    get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Index')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.id', $job->getKey())
                ->where('jobs.data.0.title', 'Vacante Pública')
                ->where('jobs.data.0.branch_name', 'Sucursal Pública')
                ->where('jobs.data.0.schedule', 'Medio tiempo')
                ->where('jobs.data.0.salary_display', '8,000')
                ->hasAll([
                    'jobs.data.0.id',
                    'jobs.data.0.title',
                    'jobs.data.0.branch_name',
                    'jobs.data.0.schedule',
                    'jobs.data.0.salary_display',
                    'jobs.data.0.created_at',
                ])
                ->has('jobs.links')
                ->has('jobs.meta'),
        );
});

it('locks the public Jobs/Show prop contract AND proves it never leaks status/organization_id/created_by', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Detalle']);
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->active()->create([
        'title' => 'Detalle Vacante',
        'description' => 'Descripción pública',
        'schedule' => 'Completo',
        'contact_info' => 'rh@example.com',
        'salary_display' => '12,000',
    ]);

    get(route('jobs.show', $job->getKey()))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Jobs/Show')
                ->where('job.title', 'Detalle Vacante')
                ->where('job.description', 'Descripción pública')
                ->where('job.schedule', 'Completo')
                ->where('job.contact_info', 'rh@example.com')
                ->where('job.branch_name', 'Sucursal Detalle')
                ->where('job.salary_display', '12,000')
                // SECURITY: a public visitor must NEVER see the lifecycle/tenant internals.
                ->missing('job.status')
                ->missing('job.organization_id')
                ->missing('job.created_by')
                ->missing('job.id'),
        );
});
