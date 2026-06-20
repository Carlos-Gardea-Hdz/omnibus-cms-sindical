<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\RepresentativeShift;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract tests for the Organization Inertia pages (CONTRACT §13/§16).
 * Inertia props are untyped at runtime, so the static gates cannot catch a
 * controller that serialises a different shape than the React page consumes. These
 * lock the EXACT snake_case prop shape each page receives — a future controller/page
 * drift fails CI. The acting user is super_admin (unconfined) so every page lists
 * the seeded rows regardless of org. Runs on PostgreSQL 18 (RefreshDatabase).
 */

/** A super_admin sees every org's rows (unconfined) — the simplest props fixture. */
function propsAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('locks the Organizations/Index prop contract (paginated, snake_case, branch_count + director_name)', function (): void {
    $municipality = Municipality::factory()->create(['name' => 'Ciudad Norte']);
    $organization = Organization::factory()->for($municipality)->create([
        'name' => 'Unión General',
        'slug' => 'union-general',
        'registered_at' => '2024-01-15',
    ]);
    Branch::factory()->for($organization)->count(2)->create();
    $director = Director::factory()->for($organization)->create(['first_name' => 'Ana', 'last_name' => 'Líder']);
    $organization->forceFill(['director_id' => $director->getKey()])->save();

    actingAs(propsAdmin())
        ->get(route('admin.organizations.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Organizations/Index')
                ->has('organizations.data', 1)
                ->where('organizations.data.0.id', $organization->getKey())
                ->where('organizations.data.0.name', 'Unión General')
                ->where('organizations.data.0.slug', 'union-general')
                ->where('organizations.data.0.municipality_name', 'Ciudad Norte')
                ->where('organizations.data.0.branch_count', 2)
                ->where('organizations.data.0.director_name', 'Ana Líder')
                ->where('organizations.data.0.registered_at', '2024-01-15')
                ->has('organizations.links')
                ->has('organizations.meta')
                ->hasAll([
                    'organizations.data.0.id',
                    'organizations.data.0.name',
                    'organizations.data.0.slug',
                    'organizations.data.0.municipality_name',
                    'organizations.data.0.branch_count',
                    'organizations.data.0.director_name',
                    'organizations.data.0.registered_at',
                ]),
        );
});

it('locks the Organizations/Create prop contract: municipality options only', function (): void {
    $municipality = Municipality::factory()->create(['name' => 'Villa Sur']);

    actingAs(propsAdmin())
        ->get(route('admin.organizations.create'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Organizations/Create')
                ->has('municipalities')
                ->where('municipalities.0.id', $municipality->getKey())
                ->where('municipalities.0.name', 'Villa Sur'),
        );
});

it('locks the Branches/Index prop contract (organization_name + representative_count)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Demo']);
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Centro', 'location' => 'Centro']);
    Representative::factory()->for($organization)->for($branch)->count(3)->create();

    actingAs(propsAdmin())
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Branches/Index')
                ->has('branches.data', 1)
                ->where('branches.data.0.id', $branch->getKey())
                ->where('branches.data.0.name', 'Sucursal Centro')
                ->where('branches.data.0.location', 'Centro')
                ->where('branches.data.0.organization_name', 'Org Demo')
                ->where('branches.data.0.representative_count', 3)
                ->has('branches.links')
                ->has('branches.meta'),
        );
});

it('locks the Directors/Index prop contract (organization_name + photo_url|null)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Director']);
    $director = Director::factory()->for($organization)->create([
        'first_name' => 'Beto',
        'last_name' => 'Guía',
        'photo_path' => null,
    ]);

    actingAs(propsAdmin())
        ->get(route('admin.directors.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Directors/Index')
                ->has('directors.data', 1)
                ->where('directors.data.0.id', $director->getKey())
                ->where('directors.data.0.first_name', 'Beto')
                ->where('directors.data.0.last_name', 'Guía')
                ->where('directors.data.0.organization_name', 'Org Director')
                ->where('directors.data.0.photo_url', null),
        );
});

it('locks the Representatives/Index prop contract (shift + shift_label_key + branch_name)', function (): void {
    $organization = Organization::factory()->create(['name' => 'Org Rep']);
    $branch = Branch::factory()->for($organization)->create(['name' => 'Sucursal Rep']);
    $representative = Representative::factory()->for($organization)->for($branch)->create([
        'first_name' => 'Carla',
        'last_name' => 'Vocal',
        'shift' => RepresentativeShift::Evening,
        'is_coordinator' => true,
    ]);

    actingAs(propsAdmin())
        ->get(route('admin.representatives.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Representatives/Index')
                ->has('representatives.data', 1)
                ->where('representatives.data.0.id', $representative->getKey())
                ->where('representatives.data.0.first_name', 'Carla')
                ->where('representatives.data.0.last_name', 'Vocal')
                ->where('representatives.data.0.shift', 'evening')
                ->where('representatives.data.0.shift_label_key', 'representative_shift.evening')
                ->where('representatives.data.0.is_coordinator', true)
                ->where('representatives.data.0.organization_name', 'Org Rep')
                ->where('representatives.data.0.branch_name', 'Sucursal Rep'),
        );
});

it('locks the Municipalities/Index prop contract (state + organizations_count, inline CRUD)', function (): void {
    $municipality = Municipality::factory()->create(['name' => 'Puerto Centro', 'state' => 'Estado Demo']);
    Organization::factory()->for($municipality)->count(2)->create();

    actingAs(propsAdmin())
        ->get(route('admin.municipalities.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Municipalities/Index')
                ->has('municipalities')
                ->where('municipalities.0.id', $municipality->getKey())
                ->where('municipalities.0.name', 'Puerto Centro')
                ->where('municipalities.0.state', 'Estado Demo')
                ->where('municipalities.0.organizations_count', 2),
        );
});
