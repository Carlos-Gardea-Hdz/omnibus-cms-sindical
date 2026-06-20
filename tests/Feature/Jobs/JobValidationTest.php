<?php

declare(strict_types=1);

use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Jobs DTO validation (CONTRACT §5/§13, SPEC §3.4). CreateJobPostingData is
 * validated via Spatie Data on the controller signature, so a failing WEB request
 * ALWAYS surfaces as a 302 redirect-back with session errors — NEVER a 422 (the
 * cardinal CMS web-validation rule). This covers the required-field + length bounds,
 * the Exists() FK rules, the integer/min:0 cents rules, and the salary-range
 * cross-field closure (max < min → a single error on salary_max_cents). The acting
 * user is super_admin (unconfined) so a bad payload fails on VALIDATION, not on org
 * confinement. Runs on PostgreSQL 18 (RefreshDatabase).
 */

function jobValidationAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/** A fully-valid baseline so each case isolates exactly one bad field. */
function validJobAttrs(Organization $organization, Branch $branch): array
{
    return [
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
        'title' => 'Vacante Válida',
        'description' => 'Una descripción válida del puesto.',
        'schedule' => 'Tiempo completo',
        'contact_info' => 'rh@example.com',
        'salary_min_cents' => 1_000_000,
        'salary_max_cents' => 2_000_000,
        'salary_display' => '10,000 - 20,000',
    ];
}

it('rejects a job create with a bad scalar field: 302, never 422, nothing created', function (Closure $mutate, string $field): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    $payload = $mutate(validJobAttrs($organization, $branch));

    actingAs(jobValidationAdmin())
        ->post(route('admin.jobs.store'), $payload)
        ->assertRedirect()
        ->assertStatus(302) // explicitly NOT 422
        ->assertSessionHasErrors($field);

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
})->with([
    'over-max title (>100)' => [
        fn (array $a): array => [...$a, 'title' => str_repeat('A', 101)],
        'title',
    ],
    'missing title' => [
        function (array $a): array {
            unset($a['title']);

            return $a;
        },
        'title',
    ],
    'missing description' => [
        function (array $a): array {
            unset($a['description']);

            return $a;
        },
        'description',
    ],
    'over-max schedule (>100)' => [
        fn (array $a): array => [...$a, 'schedule' => str_repeat('S', 101)],
        'schedule',
    ],
    'missing schedule' => [
        function (array $a): array {
            unset($a['schedule']);

            return $a;
        },
        'schedule',
    ],
    'over-max contact_info (>100)' => [
        fn (array $a): array => [...$a, 'contact_info' => str_repeat('C', 101)],
        'contact_info',
    ],
    'missing contact_info' => [
        function (array $a): array {
            unset($a['contact_info']);

            return $a;
        },
        'contact_info',
    ],
    'over-max salary_display (>50)' => [
        fn (array $a): array => [...$a, 'salary_display' => str_repeat('D', 51)],
        'salary_display',
    ],
    'missing organization_id' => [
        function (array $a): array {
            unset($a['organization_id']);

            return $a;
        },
        'organization_id',
    ],
    'non-existent organization_id' => [
        fn (array $a): array => [...$a, 'organization_id' => 999_999],
        'organization_id',
    ],
    'missing branch_id' => [
        function (array $a): array {
            unset($a['branch_id']);

            return $a;
        },
        'branch_id',
    ],
    'non-existent branch_id' => [
        fn (array $a): array => [...$a, 'branch_id' => 999_999],
        'branch_id',
    ],
    'negative salary_min_cents' => [
        fn (array $a): array => [...$a, 'salary_min_cents' => -1],
        'salary_min_cents',
    ],
    'negative salary_max_cents' => [
        fn (array $a): array => [...$a, 'salary_max_cents' => -5],
        'salary_max_cents',
    ],
]);

it('rejects a salary range where max < min (both present): 302 + error on the salary key, nothing created', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(jobValidationAdmin())
        ->post(route('admin.jobs.store'), [
            ...validJobAttrs($organization, $branch),
            'salary_min_cents' => 2_000_000,
            'salary_max_cents' => 1_000_000, // max < min
        ])
        ->assertRedirect()
        ->assertStatus(302) // never 422
        ->assertSessionHasErrors('salary_max_cents');

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

it('allows a job where only ONE of the two cents is set (range check needs BOTH)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(jobValidationAdmin())
        ->post(route('admin.jobs.store'), [
            ...validJobAttrs($organization, $branch),
            'title' => 'Solo Mínimo',
            'salary_min_cents' => 1_000_000,
            'salary_max_cents' => null, // only min present → range closure must not fire
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->where('title', 'Solo Mínimo')->exists())->toBeTrue();
});

it('allows an equal min == max salary (boundary, not a violation)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(jobValidationAdmin())
        ->post(route('admin.jobs.store'), [
            ...validJobAttrs($organization, $branch),
            'title' => 'Sueldo Fijo',
            'salary_min_cents' => 1_500_000,
            'salary_max_cents' => 1_500_000,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(JobPosting::withoutGlobalScope(OrganizationScope::class)->where('title', 'Sueldo Fijo')->exists())->toBeTrue();
});
