<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Member approval lifecycle, end-to-end (CONTRACT §6/§7/§8/§14, SPEC §3.6 §7.3). The
 * admin review routes sit behind ['auth','role:manager','org.scope']. Approve flips
 * status → Approved AND is_affiliated → true; Reject flips status → Rejected and
 * LEAVES is_affiliated false. Both Actions guard via MemberStatus::canTransitionTo();
 * an illegal edge (re-approving / re-rejecting a terminal member) throws
 * InvalidMemberTransitionException, rendered to a 302 + 'status' session error by
 * bootstrap/app.php — NEVER a 500, and the row is untouched. The acting manager is
 * CONFINED to the member's org (else the route-model binding 404s — covered in the
 * isolation crown). Runs on PostgreSQL 18 (RefreshDatabase). Fixtures are FICTIONAL.
 */

/** A manager confined to an org plus a member of that org in a known starting status. */
function memberLifecycleSetup(MemberStatus $status): array
{
    $organization = Organization::factory()->create();
    $manager = User::factory()->manager()->forOrganization($organization)->create();
    $member = Member::factory()->forOrganization($organization)->create([
        'status' => $status,
        'is_affiliated' => $status === MemberStatus::Approved,
    ]);

    return compact('manager', 'member');
}

it('approves a pending member: status → approved AND is_affiliated → true (302 + flash)', function (): void {
    ['manager' => $manager, 'member' => $member] = memberLifecycleSetup(MemberStatus::Pending);

    actingAs($manager)
        ->post(route('admin.members.approve', $member))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHas('success', __('members.approved'))
        ->assertSessionHasNoErrors();

    $fresh = $member->fresh();
    expect($fresh->status)->toBe(MemberStatus::Approved)   // enum instance
        ->and($fresh->is_affiliated)->toBeTrue();
});

it('rejects a pending member: status → rejected, is_affiliated STAYS false (302 + flash)', function (): void {
    ['manager' => $manager, 'member' => $member] = memberLifecycleSetup(MemberStatus::Pending);

    actingAs($manager)
        ->post(route('admin.members.reject', $member))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHas('success', __('members.rejected'))
        ->assertSessionHasNoErrors();

    $fresh = $member->fresh();
    expect($fresh->status)->toBe(MemberStatus::Rejected)
        ->and($fresh->is_affiliated)->toBeFalse(); // reject never affiliates
});

it('rejects an illegal approve on a terminal member: 302 + status error, status UNCHANGED, never a 500', function (MemberStatus $from): void {
    ['manager' => $manager, 'member' => $member] = memberLifecycleSetup($from);

    actingAs($manager)
        ->post(route('admin.members.approve', $member))
        ->assertRedirect()
        ->assertStatus(302) // the enum guard → InvalidMemberTransitionException → 302, not 500
        ->assertSessionHasErrors('status');

    expect($member->fresh()->status)->toBe($from); // terminal, untouched
})->with([
    'already approved → approve again' => [MemberStatus::Approved],
    'already rejected → approve' => [MemberStatus::Rejected],
]);

it('rejects an illegal reject on a terminal member: 302 + status error, status UNCHANGED, never a 500', function (MemberStatus $from): void {
    ['manager' => $manager, 'member' => $member] = memberLifecycleSetup($from);

    actingAs($manager)
        ->post(route('admin.members.reject', $member))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('status');

    expect($member->fresh()->status)->toBe($from);
})->with([
    'already rejected → reject again' => [MemberStatus::Rejected],
    'already approved → reject' => [MemberStatus::Approved],
]);

it('does not flip is_affiliated when an illegal approve is refused on an already-rejected member', function (): void {
    ['manager' => $manager, 'member' => $member] = memberLifecycleSetup(MemberStatus::Rejected);

    actingAs($manager)
        ->post(route('admin.members.approve', $member))
        ->assertSessionHasErrors('status');

    expect($member->fresh()->is_affiliated)->toBeFalse();
});
