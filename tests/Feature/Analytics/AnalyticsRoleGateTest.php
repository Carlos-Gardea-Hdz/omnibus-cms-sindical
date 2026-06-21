<?php

declare(strict_types=1);

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the admin Analytics route (CONTRACT §0/§9/§15.9, SPEC §10.2). The
 * §10.2 RBAC matrix governs (the canonical table every prior slice follows): Analytics
 * is administrator+ — gated at ['auth','role:administrator','org.scope']. So:
 *   - a guest is bounced to /login (302, NEVER 403),
 *   - editor → 403, manager → 403 (below administrator),
 *   - administrator → 200 (own-org), super_admin → 200 (all orgs).
 * The 'org.scope' middleware never aborts — it only sets context — so access is owned
 * by 'role'. Runs on PostgreSQL 18.
 *
 * NOTE (the §0 SPEC tension): §3.7 ANALYTICS-03 hints a manager could read its own org.
 * The LOCKED default is the §10.2 matrix (manager → 403). If the human flips the gate to
 * role:manager, the two manager-403 expectations below become 200 (a one-line test edit).
 */

it('redirects a guest to login (302, never 403) on the analytics route', function (): void {
    get(route('admin.analytics.index'))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
});

it('forbids an editor (403 — below administrator)', function (): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();

    actingAs($editor)
        ->get(route('admin.analytics.index'))
        ->assertForbidden();
});

it('forbids a manager (403 — the §10.2 matrix gates Analytics at administrator+)', function (): void {
    $organization = Organization::factory()->create();
    $manager = User::factory()->manager()->forOrganization($organization)->create();

    actingAs($manager)
        ->get(route('admin.analytics.index'))
        ->assertForbidden();
});

it('lets an administrator reach the dashboard (200, own-org)', function (): void {
    $organization = Organization::factory()->create();
    $administrator = User::factory()->administrator()->forOrganization($organization)->create();

    actingAs($administrator)
        ->get(route('admin.analytics.index'))
        ->assertOk();
});

it('lets a super_admin reach the dashboard (200, all orgs)', function (): void {
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.analytics.index'))
        ->assertOk();
});
