<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Identity\Enums\DemoPreset;
use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/*
 * BLOCKER 1 — demo isolation REGARDLESS of role (cross-org + PII firewall). The
 * `administrator` demo preset maps to UserRole::Administrator, which EnsureOrganizationScope
 * used to UNCONFINE — so a demo administrator read EVERY real org's rows via the global
 * OrganizationScope (member PII, the contact inbox email/phone), the demo write-block being
 * the only guard. A demo visitor is NEVER cross-org: it exists to showcase the admin UI on
 * the single seeded showcase org. These prove a demo administrator now sees ONLY the showcase
 * org's rows — the second, REAL org's branches / members / contacts are ABSENT.
 *
 * The demo IP rate-limit is cleared per-test so limiter state cannot bleed across cases.
 */

beforeEach(function (): void {
    RateLimiter::clear('demo-login');
});

/**
 * Provision a demo administrator session (POST /demo-login preset=administrator), then return
 * the freshly minted demo user (it belongs to the seeded showcase organization).
 */
function loginAsDemoAdministrator(): User
{
    post(route('demo.store'), ['preset' => DemoPreset::Administrator->value])
        ->assertRedirect(route('admin.dashboard'));

    return User::query()->whereNotNull('demo_session_id')->latest('id')->firstOrFail();
}

/**
 * Seed a fully-populated REAL (non-showcase) organization with a branch, a director, a member
 * and a contact message — everything a leak would expose.
 *
 * @return array{org: Organization, branch: Branch}
 */
function seedRealOrg(): array
{
    $org = Organization::factory()->create(['name' => 'Real Secret Org']);
    $branch = Branch::factory()->for($org)->create(['name' => 'Real Secret Branch']);
    Director::factory()->forOrganization($org)->create(['last_name' => 'SecretDirector']);
    Member::factory()->forOrganization($org)->pending()->create();
    ContactMessage::factory()->forBranch($branch)->create(['email' => 'secret@real-org.test']);

    return compact('org', 'branch');
}

it('confines a demo administrator branches index to ONLY the showcase org (the real org branch is absent)', function (): void {
    $real = seedRealOrg();

    $demoAdmin = loginAsDemoAdministrator();

    get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Branches/Index')
            // Only the showcase org's branches (zero here — the showcase org has none seeded).
            ->where('branches.data', fn ($rows) => collect($rows)
                ->every(fn ($row) => $row['organization_name'] !== 'Real Secret Org'))
        );

    // FALSIFIABLE: the real branch physically exists, the demo admin's confined read hid it.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)
        ->where('name', 'Real Secret Branch')->exists())->toBeTrue();
    // The demo admin is confined to its showcase org, NOT the real org.
    expect($demoAdmin->organization_id)->not->toBe($real['org']->getKey());
});

it('hides the real org members (PII) from a demo administrator members index', function (): void {
    seedRealOrg();

    loginAsDemoAdministrator();

    get(route('admin.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Members/Index')
            // No member rows: the only seeded member belongs to the REAL org, invisible here.
            ->has('members.data', 0)
        );

    // FALSIFIABLE: the real member physically exists; the demo admin saw none.
    expect(Member::withoutGlobalScope(OrganizationScope::class)->count())->toBe(1);
});

it('hides the real org contact inbox (email/phone PII) from a demo administrator', function (): void {
    seedRealOrg();

    loginAsDemoAdministrator();

    get(route('admin.contacts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Contacts/Index')
            ->has('messages.data', 0) // the real org's message is invisible to the demo admin
        );

    // FALSIFIABLE: the real contact message physically exists; the demo admin saw none.
    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('email', 'secret@real-org.test')->exists())->toBeTrue();
});

it('hides the real org directors from a demo administrator directors index', function (): void {
    seedRealOrg();

    loginAsDemoAdministrator();

    get(route('admin.directors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Directors/Index')
            ->where('directors.data', fn ($rows) => collect($rows)
                ->every(fn ($row) => $row['last_name'] !== 'SecretDirector'))
        );

    // FALSIFIABLE: the real director physically exists; the demo admin saw none of it.
    expect(Director::withoutGlobalScope(OrganizationScope::class)
        ->where('last_name', 'SecretDirector')->exists())->toBeTrue();
});
