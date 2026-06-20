<?php

declare(strict_types=1);

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the Organization admin routes (CONTRACT §9/§10/§16, SPEC §7.3-7.4,
 * §10.2). The level ladder splits the resources:
 *   - admin/organizations         → super_admin only;
 *   - admin/municipalities + admin/directors → administrator+;
 *   - admin/branches + admin/representatives → manager+.
 * A guest is bounced to /login (302, never 403) on every route; an under-level
 * authenticated user is a 403. The 'org.scope' middleware in these groups NEVER
 * aborts — it only sets the org context — so access is owned entirely by 'role'.
 * Runs on PostgreSQL 18 (RefreshDatabase).
 */

it('redirects a guest to login (302, never 403) on every Org-domain index', function (string $routeName): void {
    get(route($routeName))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
})->with([
    'organizations' => ['admin.organizations.index'],
    'municipalities' => ['admin.municipalities.index'],
    'directors' => ['admin.directors.index'],
    'branches' => ['admin.branches.index'],
    'representatives' => ['admin.representatives.index'],
]);

it('forbids an editor on the manager-gated branches index with a 403', function (): void {
    actingAs(User::factory()->editor()->create())
        ->get(route('admin.branches.index'))
        ->assertForbidden();
});

it('forbids an editor on the manager-gated representatives index with a 403', function (): void {
    actingAs(User::factory()->editor()->create())
        ->get(route('admin.representatives.index'))
        ->assertForbidden();
});

it('forbids a manager on the administrator-gated directors index with a 403', function (): void {
    actingAs(User::factory()->manager()->create())
        ->get(route('admin.directors.index'))
        ->assertForbidden();
});

it('forbids a manager on the administrator-gated municipalities index with a 403', function (): void {
    actingAs(User::factory()->manager()->create())
        ->get(route('admin.municipalities.index'))
        ->assertForbidden();
});

it('forbids a manager on the super_admin-gated organizations index with a 403', function (): void {
    actingAs(User::factory()->manager()->create())
        ->get(route('admin.organizations.index'))
        ->assertForbidden();
});

it('forbids an administrator on the super_admin-gated organizations index with a 403', function (): void {
    actingAs(User::factory()->administrator()->create())
        ->get(route('admin.organizations.index'))
        ->assertForbidden();
});

it('lets a super_admin reach every Org-domain index (never 403, never login)', function (string $routeName): void {
    $response = actingAs(User::factory()->superAdmin()->create())
        ->get(route($routeName));

    expect($response->getStatusCode())->not->toBe(403);
    expect($response->headers->get('Location'))->not->toBe(route('login'));
    $response->assertOk();
})->with([
    'organizations' => ['admin.organizations.index'],
    'municipalities' => ['admin.municipalities.index'],
    'directors' => ['admin.directors.index'],
    'branches' => ['admin.branches.index'],
    'representatives' => ['admin.representatives.index'],
]);

it('lets a manager reach the manager-gated branches + representatives indexes (200, auto-confined)', function (string $routeName): void {
    $organization = Organization::factory()->create();
    $manager = User::factory()->manager()->forOrganization($organization)->create();

    actingAs($manager)
        ->get(route($routeName))
        ->assertOk();
})->with([
    'branches' => ['admin.branches.index'],
    'representatives' => ['admin.representatives.index'],
]);

it('lets an administrator reach the administrator-gated municipalities + directors indexes', function (string $routeName): void {
    actingAs(User::factory()->administrator()->create())
        ->get(route($routeName))
        ->assertOk();
})->with([
    'municipalities' => ['admin.municipalities.index'],
    'directors' => ['admin.directors.index'],
]);
