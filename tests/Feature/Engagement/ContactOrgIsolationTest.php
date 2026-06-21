<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE READ CROWN — symmetric, falsifiable org confinement for the admin contact inbox
 * (CONTRACT §2/§6/§12.6, SPEC §3.5 CONTACT-02, §10.2, §11.2). ContactMessage registers
 * the global OrganizationScope in booted(); the 'org.scope' middleware confines a manager
 * to its own org and unconfines a super_admin. The inbox is READ-ONLY (there is no
 * moderation lifecycle, no write to isolate — Decision B/C), so this slice's isolation is
 * a pure READ crown:
 *
 *   A MANAGER of org A listing /admin/contacts sees ONLY A's messages (never B's).
 *   FALSIFIABLE: ContactMessage::withoutGlobalScope(OrganizationScope)->count() PHYSICALLY
 *   EXCEEDS the manager's visible count — the cross-org rows EXIST, the scope hides them.
 *   A SUPER_ADMIN is unconfined and sees ALL orgs' messages.
 *
 * Goes RED against a naive admin path that calls withoutGlobalScopes(). Runs on
 * PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

/**
 * One org with a manager, a branch, and a contact message addressed to that org.
 *
 * @return array{org: Organization, manager: User, branch: Branch, message: ContactMessage}
 */
function isolatedContactOrg(string $name): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $manager = User::factory()->manager()->forOrganization($org)->create();
    $branch = Branch::factory()->for($org)->create();
    $message = ContactMessage::factory()->forOrganization($org)->forBranch($branch)->create();

    return compact('org', 'manager', 'branch', 'message');
}

it('confines a manager listing /admin/contacts to ONLY its own organization (falsifiable vs physical count)', function (): void {
    $a = isolatedContactOrg('Org A');
    isolatedContactOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Contacts/Index')
                ->has('messages.data', 1) // only A's single message
                ->where('messages.data.0.id', $a['message']->getKey()),
        );

    // FALSIFIABLE: 2 messages physically exist, the manager saw exactly 1.
    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)->count())
        ->toBe(2)->toBeGreaterThan(1);
});

it('hides a cross-org message from a manager (the org-B row is invisible, not merely absent)', function (): void {
    $a = isolatedContactOrg('Org A');
    $b = isolatedContactOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('messages.data.0.id', $a['message']->getKey()),
        );

    // The org-B message physically exists — the scope hid it from org A's manager.
    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($b['message']->getKey())->exists())->toBeTrue();
});

it('lets a super_admin see ALL organizations contact messages (unconfined)', function (): void {
    isolatedContactOrg('Org A');
    isolatedContactOrg('Org B');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Contacts/Index')
                ->has('messages.data', 2), // A + B both visible
        );
});
