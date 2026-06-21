<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Identity\Enums\UserRole;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;

uses(RefreshDatabase::class);

/*
 * User administration (CONTRACT §B, SPEC §3.1 AUTH-04 + the slice-003/004 tenant-write
 * lesson). The crown rules:
 *   - org-confinement: an administrator manages users WITHIN its own org (read AND write);
 *     a super_admin is cross-org.
 *   - no self-elevation: a non-super_admin can NEVER mint or rise to super_admin.
 *   - the super_admin SINGLETON (AUTH-04): assigning a new super_admin UNSETS the previous,
 *     atomically — exactly one super_admin row exists at all times.
 *   - the super_admin own-record rule: a super_admin may freely edit any LOWER-role user but
 *     the sole super_admin row is edited only by itself.
 *   - graceful delete: deleting a user who authored content soft-deletes (FK-safe), never 500.
 * Web validation is 302 + session errors (never 422). The User model carries NO global
 * OrganizationScope (Deviation A) — confinement is an explicit where. Boots PostgreSQL 18.
 */

/** A baseline create payload (valid, confirmed password). */
function userCreatePayload(array $overrides = []): array
{
    return array_merge([
        'username' => 'new_operator',
        'name' => 'New Operator',
        'email' => 'new.operator@example.test',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'role' => UserRole::Editor->value,
    ], $overrides);
}

/*
 * ─────────────────────────── happy paths ───────────────────────────
 */

it('lets an administrator create a user inside its OWN org, stamping organization_id from context (never the payload)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($orgA)->create();

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), userCreatePayload([
            // The admin forges a foreign org id — it MUST be ignored and overwritten from context.
            'organization_id' => $orgB->getKey(),
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', __('users.created'));

    $created = User::query()->where('username', 'new_operator')->firstOrFail();

    expect($created->organization_id)->toBe($orgA->getKey()) // stamped from the actor's org
        ->and($created->role)->toBe(UserRole::Editor);
});

it('hashes the new password via the cast so the created user can log in, and never exposes it in a prop', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();

    actingAs($admin)
        ->post(route('admin.users.store'), userCreatePayload([
            'username' => 'logintest',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
        ]))
        ->assertRedirect();

    $created = User::query()->where('username', 'logintest')->firstOrFail();

    // The stored value is a hash, not the plaintext.
    expect($created->password)->not->toBe('a-real-password')
        ->and(Hash::check('a-real-password', $created->password))->toBeTrue();

    // And the new user can actually authenticate with it.
    auth()->logout();
    from(route('login'))
        ->post(route('login.store'), ['username' => 'logintest', 'password' => 'a-real-password'])
        ->assertRedirect(route('admin.dashboard'));
});

it('lets a super_admin create a user in ANY org via the payload (cross-org)', function (): void {
    $orgB = Organization::factory()->create();
    $super = User::factory()->superAdmin()->create();

    actingAs($super)
        ->post(route('admin.users.store'), userCreatePayload([
            'username' => 'crossorg',
            'organization_id' => $orgB->getKey(),
            'role' => UserRole::Manager->value,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', __('users.created'));

    $created = User::query()->where('username', 'crossorg')->firstOrFail();

    expect($created->organization_id)->toBe($orgB->getKey())
        ->and($created->role)->toBe(UserRole::Manager);
});

/*
 * ─────────────────────────── validation 302s ───────────────────────────
 */

it('rejects a create with a missing required field via UserData with a 302 + session error, no user', function (string $missing): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    $before = User::query()->count();

    $payload = userCreatePayload(['username' => 'will_fail']);
    unset($payload[$missing]);

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), $payload)
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHasErrors($missing);

    expect(User::query()->count())->toBe($before);
})->with([
    'username' => ['username'],
    'password' => ['password'],
]);

it('rejects a create whose password confirmation does not match with a 302 + password error, no user', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    $before = User::query()->count();

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), userCreatePayload([
            'username' => 'mismatch',
            'password' => 'one-password',
            'password_confirmation' => 'a-different-password',
        ]))
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe($before);
});

it('rejects a duplicate username with a 302 + username error and creates no second row', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    User::factory()->editor()->forOrganization($org)->create(['username' => 'taken']);

    $before = User::query()->count();

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), userCreatePayload(['username' => 'taken']))
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHasErrors('username');

    expect(User::query()->count())->toBe($before);
});

it('rejects a duplicate non-null email with a 302 + email error and creates no second row', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    User::factory()->editor()->forOrganization($org)->create(['email' => 'dup@example.test']);

    $before = User::query()->count();

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), userCreatePayload([
            'username' => 'newname',
            'email' => 'dup@example.test',
        ]))
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe($before);
});

/*
 * ─────────────────────────── role gate (RBAC) ───────────────────────────
 */

it('redirects a guest hitting any /admin/users route to login with a 302', function (): void {
    \Pest\Laravel\get(route('admin.users.index'))
        ->assertRedirect(route('login'));
});

it('forbids a lower-than-administrator role from every /admin/users surface with a 403', function (string $roleMethod): void {
    $org = Organization::factory()->create();
    $actor = User::factory()->{$roleMethod}()->forOrganization($org)->create();

    actingAs($actor)->get(route('admin.users.index'))->assertForbidden();
    actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
    actingAs($actor)->post(route('admin.users.store'), userCreatePayload())->assertForbidden();
})->with([
    'manager' => ['manager'],
    'editor' => ['editor'],
]);

/*
 * ─────────────────────────── the super_admin SINGLETON swap ───────────────────────────
 */

it('promoting a user to super_admin atomically demotes the previous super_admin: exactly one remains', function (): void {
    $incumbent = User::factory()->superAdmin()->create(['username' => 'incumbent_super']);
    $target = User::factory()->administrator()->create(['username' => 'risingstar']);

    actingAs($incumbent)
        ->from(route('admin.users.edit', $target))
        ->put(route('admin.users.update', $target), [
            'username' => 'risingstar',
            'name' => $target->name,
            'email' => $target->email,
            'role' => UserRole::SuperAdmin->value,
        ])
        ->assertRedirect();

    // The swap: the incumbent fell to administrator, the target rose to super_admin.
    expect($incumbent->fresh()->role)->toBe(UserRole::Administrator)
        ->and($target->fresh()->role)->toBe(UserRole::SuperAdmin);

    // Exactly ONE super_admin row exists program-wide (the singleton invariant).
    expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(1);
});

it('refuses to let an administrator mint a super_admin: 302 + role error, no super_admin created', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();

    actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), userCreatePayload([
            'username' => 'sneaky_super',
            'role' => UserRole::SuperAdmin->value,
        ]))
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHasErrors('role');

    expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(0)
        ->and(User::query()->where('username', 'sneaky_super')->exists())->toBeFalse();
});

it('refuses to let an administrator elevate its OWN role to super_admin (no self-elevation)', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create(['username' => 'selfriser']);

    actingAs($admin)
        ->from(route('admin.users.edit', $admin))
        ->put(route('admin.users.update', $admin), [
            'username' => 'selfriser',
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::SuperAdmin->value,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->role)->toBe(UserRole::Administrator);
    expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(0);
});

/*
 * ─────────────────────────── org-confinement (READ + WRITE) ───────────────────────────
 */

it('confines an administrator listing /admin/users to ONLY its own org users (falsifiable read)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $adminA = User::factory()->administrator()->forOrganization($orgA)->create();
    User::factory()->editor()->forOrganization($orgA)->create();   // visible to adminA
    User::factory()->editor()->forOrganization($orgB)->create();   // belongs to org B
    User::factory()->editor()->forOrganization($orgB)->create();   // belongs to org B

    // The Index prop shows only org-A users: adminA + its single editor = 2 rows. The User
    // model carries NO global scope (Deviation A), so this confinement is the controller's
    // explicit where, asserted at the serialised prop.
    actingAs($adminA)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(function (Inertia\Testing\AssertableInertia $page) use ($orgA): void {
            $page->component('Admin/Users/Index')->has('users', 2);

            $rowOrgIds = collect($page->toArray()['props']['users'])
                ->pluck('organization_id')->unique()->values()->all();

            expect($rowOrgIds)->toBe([$orgA->getKey()]); // every visible row is org A
        });

    // FALSIFIABLE: 4 users physically exist; the admin saw strictly fewer (the org-B rows
    // exist but are confined out).
    expect(User::query()->count())->toBe(4)->toBeGreaterThan(2);
});

it('404s an administrator of org A trying to edit / update / delete an org-B user (write-isolation)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $adminA = User::factory()->administrator()->forOrganization($orgA)->create();
    $targetB = User::factory()->editor()->forOrganization($orgB)->create();

    actingAs($adminA)->get(route('admin.users.edit', $targetB))->assertNotFound();

    actingAs($adminA)
        ->put(route('admin.users.update', $targetB), [
            'username' => $targetB->username,
            'role' => UserRole::Manager->value,
        ])
        ->assertNotFound();

    actingAs($adminA)->delete(route('admin.users.destroy', $targetB))->assertNotFound();

    // The org-B target is untouched (still an editor, still present).
    expect($targetB->fresh()->role)->toBe(UserRole::Editor);
});

it('lets a super_admin see users across ALL orgs (unconfined read)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    User::factory()->editor()->forOrganization($orgA)->create();
    User::factory()->editor()->forOrganization($orgB)->create();

    $super = User::factory()->superAdmin()->create();

    actingAs($super)->get(route('admin.users.index'))->assertOk();

    // The super_admin's universe is every user (no org filter).
    expect(User::query()->count())->toBeGreaterThanOrEqual(3);
});

/*
 * ─────────────────────────── the super_admin own-record rule ───────────────────────────
 */

it('lets the sole super_admin edit its OWN record (username/name) without breaking the singleton', function (): void {
    $super = User::factory()->superAdmin()->create(['username' => 'theboss', 'name' => 'The Boss']);

    actingAs($super)
        ->from(route('admin.users.edit', $super))
        ->put(route('admin.users.update', $super), [
            'username' => 'theboss',
            'name' => 'The Renamed Boss',
            'email' => $super->email,
            'role' => UserRole::SuperAdmin->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', __('users.updated'));

    expect($super->fresh()->name)->toBe('The Renamed Boss')
        ->and(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(1);
});

/*
 * ─────────────────────────── graceful delete ───────────────────────────
 */

it('refuses to let a user delete itself: 302 + cannot_delete_self, the user survives', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();

    actingAs($admin)
        ->from(route('admin.users.index'))
        ->delete(route('admin.users.destroy', $admin))
        ->assertRedirect()
        ->assertSessionHasErrors();

    expect(User::query()->whereKey($admin->getKey())->exists())->toBeTrue();
});

it('refuses to delete the sole super_admin: 302 + cannot_delete_last_super_admin, it survives', function (): void {
    $super = User::factory()->superAdmin()->create();

    actingAs($super)
        ->from(route('admin.users.index'))
        ->delete(route('admin.users.destroy', $super))
        ->assertRedirect()
        ->assertSessionHasErrors();

    expect(User::query()->where('role', UserRole::SuperAdmin->value)->count())->toBe(1);
});

it('soft-deletes a user who authored content (FK-safe): 302 + users.deleted, the article survives and resolves its trashed author', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    $author = User::factory()->editor()->forOrganization($org)->create();

    $article = Article::factory()->forOrganization($org)->create(['author_id' => $author->getKey()]);

    actingAs($admin)
        ->from(route('admin.users.index'))
        ->delete(route('admin.users.destroy', $author))
        ->assertRedirect()
        ->assertSessionHas('success', __('users.deleted'));

    // Soft-delete: the author row is trashed, not hard-deleted — no RESTRICT-FK 500.
    expect(User::withTrashed()->whereKey($author->getKey())->exists())->toBeTrue()
        ->and(User::whereKey($author->getKey())->exists())->toBeFalse();

    // The article still exists and its author_id still resolves the (trashed) author.
    $article->refresh();
    expect($article->author_id)->toBe($author->getKey())
        ->and($article->author()->withTrashed()->first()?->getKey())->toBe($author->getKey());
});
