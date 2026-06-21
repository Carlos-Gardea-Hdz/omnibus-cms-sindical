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
 * WARN 4 — the members index ?status filter is validated against MemberStatus (the enum
 * SSOT) via tryFrom: a junk value is IGNORED (treated as "no filter"), NOT raw-compared
 * against the status column (which would silently return an empty set — a confusing
 * empty-list surprise). A valid status still filters; the filters prop echoes the
 * normalized status (null for junk).
 */

/**
 * A super_admin (cross-org) plus one pending and one approved member in the same org, so
 * the unfiltered list has 2 and a valid filter narrows it to 1.
 */
function memberFilterActor(): User
{
    $org = Organization::factory()->create();
    Member::factory()->forOrganization($org)->pending()->create();
    Member::factory()->forOrganization($org)->approved()->create();

    return User::factory()->superAdmin()->create();
}

it('IGNORES an out-of-enum ?status (junk → no filter, full list returned, filter echoed null)', function (): void {
    $actor = memberFilterActor();

    actingAs($actor)
        ->get(route('admin.members.index', ['status' => 'not-a-real-status']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Members/Index')
            ->has('members.data', 2) // junk filter ignored → BOTH members visible
            ->where('filters.status', null)); // normalized to null, not the junk string
});

it('APPLIES a valid ?status (pending narrows to the single pending member)', function (): void {
    $actor = memberFilterActor();

    actingAs($actor)
        ->get(route('admin.members.index', ['status' => MemberStatus::Pending->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Members/Index')
            ->has('members.data', 1)
            ->where('members.data.0.status', MemberStatus::Pending->value)
            ->where('filters.status', MemberStatus::Pending->value));
});
