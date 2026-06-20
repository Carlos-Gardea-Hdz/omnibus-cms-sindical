<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the Jobs admin routes (CONTRACT §8/§13, SPEC §7.2). The whole
 * /admin/jobs group sits behind ['auth','role:editor','org.scope'] — Editor+ (level
 * 1). Crucially the toggle is NOT gated at manager+ (JOB-02 is editor-scoped, unlike
 * Article publish). A guest is bounced to /login (302, never 403) on every route; an
 * authenticated editor reaches the index AND may store/toggle; every role at or above
 * editor reaches the index. The 'org.scope' middleware never aborts — it only sets
 * the org context — so access is owned by 'role'. Runs on PostgreSQL 18.
 */

it('redirects a guest to login (302, never 403) on every Jobs admin route', function (string $routeName): void {
    get(route($routeName))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
})->with([
    'index' => ['admin.jobs.index'],
    'create' => ['admin.jobs.create'],
]);

it('redirects a guest POSTing /admin/jobs to login (302, never 403)', function (): void {
    $this->post(route('admin.jobs.store'), [])
        ->assertRedirect(route('login'))
        ->assertStatus(302);
});

it('lets an editor reach the jobs index (200, auto-confined)', function (): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();

    actingAs($editor)
        ->get(route('admin.jobs.index'))
        ->assertOk();
});

it('lets an editor STORE a job (the create gate is editor+, not manager+)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();

    actingAs($editor)
        ->post(route('admin.jobs.store'), [
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'title' => 'Editor Vacante',
            'description' => 'Creada por un editor.',
            'schedule' => 'Completo',
            'contact_info' => 'rh@example.com',
        ])
        ->assertRedirect(route('admin.jobs.index'))
        ->assertSessionHasNoErrors();

    expect(JobPosting::query()->withoutGlobalScopes()->where('title', 'Editor Vacante')->exists())->toBeTrue();
});

it('lets an editor TOGGLE a job status (JOB-02 is editor-scoped, NOT manager-gated)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();
    $job = JobPosting::factory()->forOrganization($organization)->forBranch($branch)->create([
        'status' => JobStatus::Active,
    ]);

    actingAs($editor)
        ->post(route('admin.jobs.status', $job), ['status' => JobStatus::Paused->value])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($job->fresh()->status)->toBe(JobStatus::Paused);
});

it('lets every role at or above editor reach the jobs index (200, never 403, never login)', function (string $role): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->{$role}()->forOrganization($organization)->create();

    $response = actingAs($user)->get(route('admin.jobs.index'));

    expect($response->getStatusCode())->not->toBe(403);
    expect($response->headers->get('Location'))->not->toBe(route('login'));
    $response->assertOk();
})->with([
    'editor' => ['editor'],
    'manager' => ['manager'],
    'administrator' => ['administrator'],
    'super_admin' => ['superAdmin'],
]);
