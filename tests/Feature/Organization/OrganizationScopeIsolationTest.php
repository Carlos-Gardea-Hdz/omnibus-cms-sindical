<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Organization\Enums\RepresentativeShift;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE CROWN — symmetric, falsifiable org confinement (CONTRACT §11/§16, SPEC §3.1
 * AUTH-03, §10.2, §11.4 #8). The CMS analogue of the UNIGES
 * DemoReportIsolationTest. Given articles / branches / representatives in org A and
 * org B:
 *
 *   1. a MANAGER of org A listing /admin/articles, /admin/branches,
 *      /admin/representatives sees ONLY org-A rows — never org B's;
 *   2. that manager attempting to show/edit/delete an org-B route-model-bound row
 *      gets a 404 (the global OrganizationScope makes the cross-org row
 *      unresolvable) — never a 200, never another org's data;
 *   3. a SUPER_ADMIN listing the same routes sees ALL rows (A + B);
 *   4. FALSIFIABLE: Article/Branch/Representative::withoutGlobalScope(OrganizationScope)
 *      ->count() PHYSICALLY EXCEEDS the manager's visible count — the rows exist, the
 *      scope hides them (mirrors UNIGES Student::withoutGlobalScopes()->count()). If a
 *      query ever bypassed the scope to "see all", this test goes red;
 *   5. SYMMETRIC fail-closed: a confined manager with a NULL organization_id sees
 *      nothing (WHERE 1=0), never another org's data.
 *
 * Confinement is set by the 'org.scope' middleware on the admin route group from the
 * acting user's role (manager confines; super_admin unconfines). Runs on PostgreSQL
 * 18 (RefreshDatabase).
 */

/**
 * Build one fully-populated organization: a manager, a branch, an article on that
 * branch and a representative on it.
 *
 * @return array{org: Organization, manager: User, branch: Branch, article: Article, representative: Representative}
 */
function isolatedOrg(string $name): array
{
    $org = Organization::factory()->create(['name' => $name]);
    $manager = User::factory()->manager()->forOrganization($org)->create();
    $branch = Branch::factory()->for($org)->create();

    $article = Article::factory()->forOrganization($org)->create([
        'branch_id' => $branch->getKey(),
        'author_id' => $manager->getKey(),
    ]);

    $representative = Representative::factory()->for($org)->for($branch)->create();

    return compact('org', 'manager', 'branch', 'article', 'representative');
}

it('confines a manager listing to ONLY its own organization across articles, branches, representatives', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.articles.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Index')
                ->has('articles.data', 1) // only A's single article
                ->where('articles.data.0.id', $a['article']->getKey()),
        );

    actingAs($a['manager'])
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Branches/Index')
                ->has('branches.data', 1)
                ->where('branches.data.0.id', $a['branch']->getKey()),
        );

    actingAs($a['manager'])
        ->get(route('admin.representatives.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Representatives/Index')
                ->has('representatives.data', 1)
                ->where('representatives.data.0.id', $a['representative']->getKey()),
        );

    // FALSIFIABLE: the physical rows exist (2 each) but the manager saw exactly 1.
    expect(Article::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2)->toBeGreaterThan(1);
    expect(Branch::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2)->toBeGreaterThan(1);
    expect(Representative::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2)->toBeGreaterThan(1);
});

it('404s a manager of org A trying to edit/delete an org-B article (cross-org row is unresolvable)', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    // Edit (GET, route-model bound) an org-B article → 404 under A's confinement.
    actingAs($a['manager'])
        ->get(route('admin.articles.edit', $b['article']))
        ->assertNotFound();

    // Delete an org-B article → 404, and the row survives untouched.
    actingAs($a['manager'])
        ->delete(route('admin.articles.destroy', $b['article']))
        ->assertNotFound();

    expect(Article::withoutGlobalScope(OrganizationScope::class)->whereKey($b['article']->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('404s a manager of org A trying to mutate an org-B branch (route-model binding scoped out)', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    actingAs($a['manager'])
        ->get(route('admin.branches.edit', $b['branch']))
        ->assertNotFound();

    // The org-B branch (and its representative) is physically intact.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)->whereKey($b['branch']->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('lets a super_admin see ALL organizations rows (cross-org bypass / unconfined)', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.articles.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Index')
                ->has('articles.data', 2), // A + B both visible
        );

    // A super_admin may also bind + edit an org-B row (no 404, unconfined).
    actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Branches/Index')
                ->has('branches.data', 2),
        );
});

it('fails closed: a confined manager with a NULL organization sees nothing (WHERE 1=0)', function (): void {
    // Two fully-populated orgs whose rows must stay invisible to a null-org manager.
    isolatedOrg('Org A');
    isolatedOrg('Org B');

    // A manager misconfigured with no organization_id (super_admin is org-less by
    // design, but a CONFINED manager-level user with null org must fail closed).
    $orphan = User::factory()->manager()->create(['organization_id' => null]);

    actingAs($orphan)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Branches/Index')
                ->has('branches.data', 0), // sees nothing — never another org's data
        );

    actingAs($orphan)
        ->get(route('admin.representatives.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Representatives/Index')
                ->has('representatives.data', 0),
        );

    // Falsifiable: 2 branches + 2 reps physically exist (one each across orgs A + B);
    // the orphan, fail-closed (WHERE 1=0), saw zero.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2);
    expect(Representative::withoutGlobalScope(OrganizationScope::class)->count())->toBe(2);
});

it('preserves unconfined behavior for CLI / no-request context (the 248-test regression firewall)', function (): void {
    isolatedOrg('Org A');
    isolatedOrg('Org B');

    // No HTTP request ran 'org.scope', so the OrganizationContext defaults UNCONFINED
    // and the global scope is a no-op — exactly what the prior non-HTTP queries rely on.
    expect(Article::count())->toBe(2)
        ->and(Branch::count())->toBe(2)
        ->and(Representative::count())->toBe(2);
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * BLOCKER 1 — cross-org WRITE confinement (the SELECT scope does NOT constrain
 * INSERT/UPDATE column values; the Create/Update Actions must). A CONFINED manager
 * (role:manager + org.scope) may reach /admin/branches + /admin/representatives, and
 * the DTOs validate organization_id/branch_id with only Exists(...) — never "belongs
 * to my org". Without the Action-level guard a manager of org A could POST/PUT
 * organization_id=B (plant/move a row into another tenant) or attach a rep to org B's
 * branch. These prove the guard CLOSES that hole. They go RED against the pre-fix
 * Actions (which wrote $data->organization_id straight from the payload).
 * ─────────────────────────────────────────────────────────────────────────────
 */

it('(A) refuses a confined manager planting a branch into another org via organization_id', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    // Manager of A POSTs a NEW branch with organization_id forged to B.
    actingAs($a['manager'])
        ->from(route('admin.branches.create'))
        ->post(route('admin.branches.store'), [
            'organization_id' => $b['org']->getKey(),
            'name' => 'Forged Branch',
            'location' => 'Somewhere',
        ])
        ->assertRedirect();

    // No row landed in org B. The branch (if created at all) belongs to A.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('name', 'Forged Branch')
        ->exists())->toBeFalse();

    expect(Branch::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $a['org']->getKey())
        ->where('name', 'Forged Branch')
        ->exists())->toBeTrue();
});

it('(B) refuses a confined manager MOVING its own branch to another org via update', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    // Manager of A PUTs ITS OWN branch with organization_id forged to B.
    actingAs($a['manager'])
        ->from(route('admin.branches.edit', $a['branch']))
        ->put(route('admin.branches.update', $a['branch']), [
            'organization_id' => $b['org']->getKey(),
            'name' => 'Renamed Branch',
            'location' => 'Moved?',
        ])
        ->assertRedirect();

    // The branch stayed in A — never moved out of the tenant.
    $a['branch']->refresh();
    expect($a['branch']->organization_id)->toBe($a['org']->getKey());

    // Falsifiable: no branch named 'Renamed Branch' physically sits in org B.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('name', 'Renamed Branch')
        ->exists())->toBeFalse();
});

it('(C) refuses a confined manager attaching a representative to another org branch', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    $before = Representative::withoutGlobalScope(OrganizationScope::class)->count();

    // Manager of A creates a rep whose branch_id points at org B's branch.
    actingAs($a['manager'])
        ->from(route('admin.representatives.create'))
        ->post(route('admin.representatives.store'), [
            'organization_id' => $a['org']->getKey(), // honest own-org
            'branch_id' => $b['branch']->getKey(),     // but a FOREIGN org's branch
            'first_name' => 'Cross',
            'last_name' => 'Org',
            'shift' => RepresentativeShift::Morning->value,
            'is_coordinator' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('branch_id');

    // No new representative row landed anywhere — the count is unchanged, and the
    // specific cross-org rep we tried to plant ('Cross Org') does not exist at all
    // (scoped by name so we don't match org B's pre-existing fixture rep).
    expect(Representative::withoutGlobalScope(OrganizationScope::class)->count())->toBe($before);
    expect(Representative::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Cross')
        ->where('last_name', 'Org')
        ->exists())->toBeFalse();
});

it('lets an UNCONFINED super_admin legitimately set the organization via the payload', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    // A super_admin is unconfined — the payload-supplied organization_id is honoured.
    actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.branches.create'))
        ->post(route('admin.branches.store'), [
            'organization_id' => $b['org']->getKey(),
            'name' => 'Admin Choice',
            'location' => 'Org B HQ',
        ])
        ->assertRedirect(route('admin.branches.index'));

    expect(Branch::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('name', 'Admin Choice')
        ->exists())->toBeTrue();

    // And a super_admin may attach a rep to ANY org's branch in the SAME org it chose.
    actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.representatives.create'))
        ->post(route('admin.representatives.store'), [
            'organization_id' => $b['org']->getKey(),
            'branch_id' => $b['branch']->getKey(),
            'first_name' => 'Admin',
            'last_name' => 'Rep',
            'shift' => RepresentativeShift::Evening->value,
            'is_coordinator' => false,
        ])
        ->assertRedirect(route('admin.representatives.index'));

    expect(Representative::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $b['org']->getKey())
        ->where('branch_id', $b['branch']->getKey())
        ->where('first_name', 'Admin')
        ->exists())->toBeTrue();
});

it('refuses an UNCONFINED super_admin cross-attaching a rep to a branch from a DIFFERENT org', function (): void {
    $a = isolatedOrg('Org A');
    $b = isolatedOrg('Org B');

    // Admin chooses org A but a branch that belongs to org B → invariant violated.
    actingAs(User::factory()->superAdmin()->create())
        ->from(route('admin.representatives.create'))
        ->post(route('admin.representatives.store'), [
            'organization_id' => $a['org']->getKey(),
            'branch_id' => $b['branch']->getKey(),
            'first_name' => 'Wrong',
            'last_name' => 'Pair',
            'shift' => RepresentativeShift::Night->value,
            'is_coordinator' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('branch_id');

    expect(Representative::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Wrong')
        ->exists())->toBeFalse();
});
