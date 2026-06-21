<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * PUBLIC member registration (CONTRACT §7/§8/§14, SPEC §3.6, §7.3). The sole CREATE
 * path for a member is the anonymous POST /membership/register — there is NO admin
 * create. RegisterMemberAction takes NO OrganizationContext (Decision F): the public
 * caller is unconfined, so an unauthenticated visitor lands a row, status defaults to
 * Pending, is_affiliated to false, and the payload organization_id is honoured AS-IS
 * (nullable — an org-less registration is legitimate). The PII fields (curp/rfc) are
 * passed plain and the Eloquent cast encrypts them. The route carries throttle:5,60 so
 * a 6th POST inside the window 429s. Web validation is 302 + session errors, never 422.
 * Runs on PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/** A fully-valid, FICTIONAL registration payload (snake_case, as the React form posts). */
function memberRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Juan',
        'last_name_paternal' => 'Pérez',
        'last_name_maternal' => 'López',
        'curp' => 'XEXX010101HNEXXXA4',  // FICTIONAL — pattern-valid only
        'rfc' => 'XEXX010101000',        // FICTIONAL — pattern-valid only
        'date_of_birth' => '1990-05-15',
        'municipality_id' => Municipality::factory()->create()->getKey(),
        'address' => 'Calle Falsa 123',
        'postal_code' => '01234',
        'neighborhood' => 'Centro',
        'mobile' => '5512345678',
        'phone' => '5598765432',
    ], $overrides);
}

it('lets an anonymous visitor register: a pending, non-affiliated member lands (302 + success)', function (): void {
    $organization = Organization::factory()->create();

    post(route('membership.store'), memberRegistrationPayload([
        'organization_id' => $organization->getKey(),
        'first_name' => 'Registrante',
    ]))
        ->assertRedirect(route('membership.create'))
        ->assertStatus(302)
        ->assertSessionHas('success', __('membership.registered'))
        ->assertSessionHasNoErrors();

    $member = Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Registrante')->sole();

    // Enum-cast attribute compared as an ENUM INSTANCE; the lifecycle/affiliation defaults
    // are server-owned, never from the payload.
    expect($member->status)->toBe(MemberStatus::Pending)
        ->and($member->is_affiliated)->toBeFalse()
        ->and($member->organization_id)->toBe($organization->getKey());
});

it('ignores a hostile status/is_affiliated in the registration payload (no self-approval)', function (): void {
    // The highest-stakes attack on this anonymous endpoint is a self-approving
    // registrant. RegisterMemberData has no status/is_affiliated fields (Spatie Data
    // discards unknown keys) AND RegisterMemberAction hardcodes the defaults — so the
    // malicious keys are inert and the row still lands Pending + unaffiliated.
    post(route('membership.store'), memberRegistrationPayload([
        'first_name' => 'Tamper',
        'status' => 'approved',
        'is_affiliated' => true,
    ]))
        ->assertRedirect(route('membership.create'))
        ->assertSessionHas('success', __('membership.registered'));

    $member = Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Tamper')->sole();

    expect($member->status)->toBe(MemberStatus::Pending)
        ->and($member->is_affiliated)->toBeFalse();
});

it('accepts an org-less registration (organization_id omitted → null org)', function (): void {
    post(route('membership.store'), memberRegistrationPayload([
        'first_name' => 'SinOrg',
        // no organization_id key at all
    ]))
        ->assertRedirect(route('membership.create'))
        ->assertSessionHasNoErrors();

    $member = Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'SinOrg')->sole();

    expect($member->organization_id)->toBeNull()
        ->and($member->status)->toBe(MemberStatus::Pending);
});

it('enforces the throttle:5,60 rate limit: the first 5 POSTs pass, the 6th 429s', function (): void {
    // The limiter is backed by the array cache store (phpunit.xml CACHE_STORE=array),
    // which is recreated per test by the fresh application instance — so this test starts
    // with a clean budget of 5 without any manual clear.

    // 5 successful registrations inside the window.
    for ($i = 1; $i <= 5; $i++) {
        post(route('membership.store'), memberRegistrationPayload([
            'first_name' => "Throttle{$i}",
        ]))->assertStatus(302)->assertSessionHasNoErrors();
    }

    // The 6th is rate-limited — 429, and NO sixth row is written.
    post(route('membership.store'), memberRegistrationPayload([
        'first_name' => 'Throttle6',
    ]))->assertStatus(429);

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Throttle6')->exists())->toBeFalse();

    expect(Member::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'like', 'Throttle%')->count())->toBe(5);
});
