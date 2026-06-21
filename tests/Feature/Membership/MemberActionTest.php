<?php

declare(strict_types=1);

use App\Domain\Membership\Actions\ApproveMemberAction;
use App\Domain\Membership\Actions\RegisterMemberAction;
use App\Domain\Membership\Actions\RejectMemberAction;
use App\Domain\Membership\Data\RegisterMemberData;
use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Exceptions\InvalidMemberTransitionException;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Action-level tests (CONTRACT §7/§14, SPEC §11.5). Each Action is exercised directly
 * (Arrange-Act-Assert) WITHOUT the HTTP layer, so the registration defaults, the
 * payload-org honoured-as-is rule (Decision F — RegisterMemberAction takes NO
 * OrganizationContext, the public caller is unconfined), and the transition guards are
 * proven in isolation. PII is FICTIONAL (pattern-valid, never real). Runs on PostgreSQL 18.
 */

/** Build a RegisterMemberData from overrides over a valid FICTIONAL baseline. */
function registerMemberData(array $overrides = []): RegisterMemberData
{
    return RegisterMemberData::from(array_merge([
        'first_name' => 'Action',
        'last_name_paternal' => 'Apellido',
        'last_name_maternal' => 'Materno',
        'curp' => 'XEXX010101HNEXXXA4',  // FICTIONAL
        'rfc' => 'XEXX010101000',        // FICTIONAL
        'date_of_birth' => '1991-01-01',
        'municipality_id' => Municipality::factory()->create()->getKey(),
        'address' => 'Calle Acción 1',
        'postal_code' => '00100',
        'neighborhood' => 'Centro',
        'mobile' => '5500000001',
        'phone' => null,
    ], $overrides));
}

it('RegisterMemberAction creates a Pending, non-affiliated member and honours the payload org AS-IS', function (): void {
    $organization = Organization::factory()->create();

    $member = app(RegisterMemberAction::class)->handle(registerMemberData([
        'organization_id' => $organization->getKey(),
    ]));

    expect($member->status)->toBe(MemberStatus::Pending)   // server-owned default
        ->and($member->is_affiliated)->toBeFalse()          // never affiliated on registration
        ->and($member->organization_id)->toBe($organization->getKey()); // payload as-is (unconfined)
});

it('RegisterMemberAction accepts a null org (org-less registration is legitimate)', function (): void {
    $member = app(RegisterMemberAction::class)->handle(registerMemberData([
        'organization_id' => null,
    ]));

    expect($member->organization_id)->toBeNull()
        ->and($member->status)->toBe(MemberStatus::Pending);
});

it('RegisterMemberAction persists curp/rfc through the encrypted cast (round-trips to plaintext)', function (): void {
    $member = app(RegisterMemberAction::class)->handle(registerMemberData([
        'curp' => 'XEXX010101MNEXXXA8',
        'rfc' => 'ABC010101AB1',
    ]));

    // Read back from a fresh model — the cast decrypts the stored ciphertext.
    $fresh = Member::withoutGlobalScope(OrganizationScope::class)->whereKey($member->getKey())->sole();
    expect($fresh->curp)->toBe('XEXX010101MNEXXXA8')
        ->and($fresh->rfc)->toBe('ABC010101AB1');
});

it('ApproveMemberAction flips a pending member to approved + affiliated', function (): void {
    $member = Member::factory()->pending()->create();

    $result = app(ApproveMemberAction::class)->handle($member);

    expect($result->status)->toBe(MemberStatus::Approved)
        ->and($result->is_affiliated)->toBeTrue()
        ->and($member->fresh()->status)->toBe(MemberStatus::Approved);
});

it('RejectMemberAction flips a pending member to rejected and leaves is_affiliated false', function (): void {
    $member = Member::factory()->pending()->create();

    $result = app(RejectMemberAction::class)->handle($member);

    expect($result->status)->toBe(MemberStatus::Rejected)
        ->and($result->is_affiliated)->toBeFalse();
});

it('ApproveMemberAction throws InvalidMemberTransitionException on a terminal member, leaving it untouched', function (): void {
    $member = Member::factory()->rejected()->create();

    expect(fn (): Member => app(ApproveMemberAction::class)->handle($member))
        ->toThrow(InvalidMemberTransitionException::class);

    expect($member->fresh()->status)->toBe(MemberStatus::Rejected); // terminal, untouched
});

it('RejectMemberAction throws InvalidMemberTransitionException on a terminal member, leaving it untouched', function (): void {
    $member = Member::factory()->approved()->create();

    expect(fn (): Member => app(RejectMemberAction::class)->handle($member))
        ->toThrow(InvalidMemberTransitionException::class);

    expect($member->fresh()->status)->toBe(MemberStatus::Approved);
});
