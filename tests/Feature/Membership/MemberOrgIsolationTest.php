<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE CROWN — symmetric, falsifiable org confinement for the Membership domain
 * (CONTRACT §8/§14, SPEC §3.6 / §10.2 / §11.2; the slice-003/004 tenant-write lesson).
 * Membership has a DIFFERENT write shape from Jobs: there is NO admin create/update and
 * NO branch_id, so the WRITE crown is the LIFECYCLE 404 — a confined manager of org A
 * approving/rejecting an org-B {member} must 404 (route-model binding under the global
 * OrganizationScope, since 'org.scope' runs BEFORE SubstituteBindings) and the org-B
 * row must stay physically pending. Given members across org A, org B, and an org-less
 * (null-org) member:
 *
 *   READ  — a MANAGER of A listing /admin/members sees ONLY A's members; the org-B and
 *           the org-less members are invisible to it. FALSIFIABLE:
 *           Member::withoutGlobalScope(OrganizationScope)->count() PHYSICALLY EXCEEDS the
 *           manager's visible count — the rows exist, the scope hides them. A SUPER_ADMIN
 *           sees ALL members including the org-less one (unconfined).
 *
 *   WRITE — a confined manager of A POSTing approve/reject for an org-B member 404s and
 *           the org-B member is still physically pending (the binding never resolved a
 *           cross-org row, so no Action ran). A confined manager can NEVER mutate another
 *           org's member.
 *
 * Confinement is set by the 'org.scope' middleware from the acting user's role (manager
 * confines; super_admin unconfines). Runs on PostgreSQL 18. Fixtures are FICTIONAL.
 */

/**
 * One org with a manager and a pending member of that org.
 *
 * @return array{org: Organization, manager: User, member: Member}
 */
function isolatedMemberOrg(string $name): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $manager = User::factory()->manager()->forOrganization($org)->create();
    $member = Member::factory()->forOrganization($org)->pending()->create([
        'first_name' => "Miembro {$name}",
    ]);

    return compact('org', 'manager', 'member');
}

/*
 * ─────────────────────────── READ confinement ───────────────────────────
 */

it('confines a manager listing /admin/members to ONLY its own organization (falsifiable vs physical count)', function (): void {
    $a = isolatedMemberOrg('Org A');
    $b = isolatedMemberOrg('Org B');
    // an org-less member must ALSO be invisible to the confined manager
    Member::factory()->orgLess()->pending()->create(['first_name' => 'Sin Org']);

    actingAs($a['manager'])
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Members/Index')
                ->has('members.data', 1) // ONLY A's single member
                ->where('members.data.0.id', $a['member']->getKey()),
        );

    // FALSIFIABLE: 3 members physically exist (A, B, org-less); the manager saw exactly 1.
    expect(Member::withoutGlobalScope(OrganizationScope::class)->count())
        ->toBe(3)->toBeGreaterThan(1);
});

it('hides an org-less (null-org) member from a confined manager but shows it to a super_admin', function (): void {
    $a = isolatedMemberOrg('Org A');
    Member::factory()->orgLess()->pending()->create(['first_name' => 'Huérfano']);

    // Confined manager: org-less member is NOT in its scope.
    actingAs($a['manager'])
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->has('members.data', 1)
                ->where('members.data.0.id', $a['member']->getKey()),
        );
});

it('lets a super_admin see ALL members across orgs INCLUDING the org-less one (unconfined)', function (): void {
    isolatedMemberOrg('Org A');
    isolatedMemberOrg('Org B');
    Member::factory()->orgLess()->pending()->create(['first_name' => 'Global Visible']);

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Members/Index')
                ->has('members.data', 3), // A + B + org-less all visible
        );
});

/*
 * ─────────────────────────── WRITE confinement (THE CROWN: the lifecycle 404) ──────────────
 * No admin create/update + no branch_id ⇒ the write hole a naive impl would leave is the
 * cross-org lifecycle mutation. The global OrganizationScope makes the org-B {member}
 * binding unresolvable for a confined manager of A → 404 BEFORE any Action runs. These go
 * RED against an impl that bypassed the scope on the admin path (e.g. withoutGlobalScopes).
 */

it('404s a confined manager of A APPROVING an org-B member, leaving it physically pending', function (): void {
    $a = isolatedMemberOrg('Org A');
    $b = isolatedMemberOrg('Org B');

    actingAs($a['manager'])
        ->post(route('admin.members.approve', $b['member']))
        ->assertNotFound();

    // Falsifiable: the org-B member never moved — still pending, never affiliated.
    $physical = Member::withoutGlobalScope(OrganizationScope::class)->whereKey($b['member']->getKey())->sole();
    expect($physical->status)->toBe(MemberStatus::Pending)
        ->and($physical->is_affiliated)->toBeFalse();
});

it('404s a confined manager of A REJECTING an org-B member, leaving it physically pending', function (): void {
    $a = isolatedMemberOrg('Org A');
    $b = isolatedMemberOrg('Org B');

    actingAs($a['manager'])
        ->post(route('admin.members.reject', $b['member']))
        ->assertNotFound();

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($b['member']->getKey())->value('status'))->toBe(MemberStatus::Pending);
});

it('404s a confined manager of A even on an org-less member (null org is not the managers org)', function (): void {
    $a = isolatedMemberOrg('Org A');
    $orgLess = Member::factory()->orgLess()->pending()->create(['first_name' => 'Sin Org Target']);

    actingAs($a['manager'])
        ->post(route('admin.members.approve', $orgLess))
        ->assertNotFound();

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($orgLess->getKey())->value('status'))->toBe(MemberStatus::Pending);
});

it('lets a confined manager of A legitimately approve ITS OWN member (the binding resolves)', function (): void {
    $a = isolatedMemberOrg('Org A');

    actingAs($a['manager'])
        ->post(route('admin.members.approve', $a['member']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($a['member']->fresh()->status)->toBe(MemberStatus::Approved);
});
