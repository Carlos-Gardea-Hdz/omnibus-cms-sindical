<?php

declare(strict_types=1);

use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Cross-slice referential-integrity regression for the new members FKs (CONTRACT §1/§9/
 * §14, SPEC §3.2 §3.6, Decisions B/I). Two FKs land on members:
 *
 *   municipality_id → RESTRICT (restrictOnDelete). So DeleteMunicipalityAction must be
 *     EXTENDED to pre-check member referrers (Decision I): a municipality referenced ONLY
 *     by a member is refused gracefully — 302 + 'municipality' error, the row SURVIVES,
 *     never a 500 (the restrict FK is never tripped because the pre-check fires first).
 *
 *   organization_id → SET NULL (nullOnDelete). So deleting an org that has members must
 *     NOT block and must NOT 500 — it NULLs the members' organization_id (Decision B).
 *     DeleteOrganizationAction is deliberately NOT given a member pre-check.
 *
 * The municipalities gate is administrator+; org deletion runs through its own admin
 * resource. Runs on PostgreSQL 18 (RefreshDatabase). Fixtures are FICTIONAL.
 */

/** An administrator actor (the municipalities gate is administrator+). */
function memberRegressionAdmin(): User
{
    return User::factory()->administrator()->create();
}

/** A super_admin actor (admin/organizations is gated role:super_admin in routes/web.php). */
function memberRegressionSuperAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('refuses to delete a municipality referenced ONLY by a member (302 + in-use, survives, NOT 500)', function (): void {
    $municipality = Municipality::factory()->create();
    // The ONLY referrer is a member — no organization points at this municipality.
    Member::factory()->forMunicipality($municipality)->pending()->create();

    actingAs(memberRegressionAdmin())
        ->delete(route('admin.municipalities.destroy', $municipality))
        ->assertRedirect()
        ->assertStatus(302) // graceful refusal, never a 500 from the restrict FK
        ->assertSessionHasErrors(['municipality' => __('municipalities.error.in_use')]);

    // Hard-delete catalog: the pre-check stopped it, so the row physically survives.
    expect(Municipality::query()->whereKey($municipality->getKey())->exists())->toBeTrue();
});

it('still refuses a municipality referenced by a SOFT-DELETED member (withTrashed pre-check)', function (): void {
    $municipality = Municipality::factory()->create();
    $member = Member::factory()->forMunicipality($municipality)->pending()->create();
    $member->delete(); // soft-delete: the FK column still physically holds municipality_id

    actingAs(memberRegressionAdmin())
        ->delete(route('admin.municipalities.destroy', $municipality))
        ->assertRedirect()
        ->assertSessionHasErrors('municipality');

    expect(Municipality::query()->whereKey($municipality->getKey())->exists())->toBeTrue();
});

it('deletes a municipality that no member nor organization references', function (): void {
    $municipality = Municipality::factory()->create();

    actingAs(memberRegressionAdmin())
        ->delete(route('admin.municipalities.destroy', $municipality))
        ->assertRedirect()
        ->assertSessionHas('success', __('municipalities.deleted'))
        ->assertSessionHasNoErrors();

    expect(Municipality::query()->whereKey($municipality->getKey())->exists())->toBeFalse();
});

it('deletes an organization that has members WITHOUT blocking and WITHOUT 500ing (Decision B — members are SET NULL, never RESTRICT)', function (): void {
    // The org has NO branches, so DeleteOrganizationAction (UNTOUCHED — no member pre-check)
    // proceeds. The members FK is SET NULL / nullable, never RESTRICT, so members never block
    // the delete. The org delete is a SOFT delete (assertSoftDeleted, OrganizationCrudTest), so
    // the DB-level nullOnDelete does NOT fire on the deleted_at UPDATE — the member therefore
    // SURVIVES intact (never cascade-deleted). The load-bearing invariants this pins: the delete
    // SUCCEEDS (no 'organization' member block, no 500) and the member ROW persists.
    $organization = Organization::factory()->create();
    $member = Member::factory()->forOrganization($organization)->pending()->create();

    // admin/organizations is super_admin-only (routes/web.php) — use a super_admin actor,
    // mirroring OrganizationCrudTest; the municipality cases above stay on administrator.
    actingAs(memberRegressionSuperAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasNoErrors(); // never blocks on members, never 500s

    // The org is soft-deleted; the member is NOT cascade-deleted — it physically survives.
    $this->assertSoftDeleted('organizations', ['id' => $organization->getKey()]);

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($member->getKey())->exists())->toBeTrue();
});
