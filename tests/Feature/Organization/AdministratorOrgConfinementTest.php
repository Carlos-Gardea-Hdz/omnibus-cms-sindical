<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * BLOCKER 1 (part 2) — a NON-demo administrator is OWN-ORG confined on the index
 * controllers (defense-in-depth + consistency with AnalyticsController / UserController,
 * which already pin an administrator to its own org per the §10.2 RBAC matrix:
 * administrator = own-org, super_admin = all). EnsureOrganizationScope still UNCONFINES an
 * administrator through the global scope, so each index applies an EXPLICIT own-org `where`
 * for any non-super_admin actor. These prove an administrator of org A NEVER sees org B's
 * rows across branches / representatives / directors / members / contacts, while a
 * super_admin keeps its legitimate cross-org view of every org.
 */

/**
 * Build a populated org: an administrator pinned to it, a branch, a representative on that
 * branch, a director, a pending member and a contact message.
 *
 * @return array{org: Organization, admin: User, branch: Branch}
 */
function populatedOrg(string $name): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    $branch = Branch::factory()->for($org)->create(['name' => "{$name} Branch"]);
    Representative::factory()->for($org)->for($branch)->create(['last_name' => "{$name}Rep"]);
    Director::factory()->forOrganization($org)->create(['last_name' => "{$name}Director"]);
    Member::factory()->forOrganization($org)->pending()->create();
    ContactMessage::factory()->forBranch($branch)->create(['email' => "inbox@{$name}.test"]);

    return compact('org', 'admin', 'branch');
}

it('confines a NON-demo administrator of org A to its own org across every index (org B absent)', function (): void {
    $a = populatedOrg('OrgA');
    $b = populatedOrg('OrgB');

    actingAs($a['admin'])->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Branches/Index')
            ->has('branches.data', 1)
            ->where('branches.data.0.id', $a['branch']->getKey()));

    actingAs($a['admin'])->get(route('admin.representatives.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Representatives/Index')
            ->has('representatives.data', 1)
            ->where('representatives.data.0.last_name', 'OrgARep'));

    actingAs($a['admin'])->get(route('admin.directors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Directors/Index')
            ->has('directors.data', 1)
            ->where('directors.data.0.last_name', 'OrgADirector'));

    actingAs($a['admin'])->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Members/Index')
            ->has('members.data', 1)); // only org A's member

    actingAs($a['admin'])->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Contacts/Index')
            ->has('messages.data', 1)
            ->where('messages.data.0.email', 'inbox@OrgA.test'));
});

it('lets a super_admin keep cross-org visibility across the same indexes (both orgs visible)', function (): void {
    populatedOrg('OrgA');
    populatedOrg('OrgB');

    $super = User::factory()->superAdmin()->create();

    actingAs($super)->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Branches/Index')
            ->has('branches.data', 2)); // A + B

    actingAs($super)->get(route('admin.directors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Directors/Index')
            ->has('directors.data', 2));

    actingAs($super)->get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Members/Index')
            ->has('members.data', 2));

    actingAs($super)->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Contacts/Index')
            ->has('messages.data', 2));
});
