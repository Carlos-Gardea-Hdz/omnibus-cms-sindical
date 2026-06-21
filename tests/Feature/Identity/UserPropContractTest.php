<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract test for the User-administration Inertia pages (CONTRACT §D).
 * Inertia props are untyped at runtime, so the static gates cannot catch a controller
 * that serialises a different snake_case shape than the React page consumes — nor a
 * password / remember_token LEAK. These lock the EXACT shape per page and assert no
 * secret ever crosses the wire. The assignable-role set is actor-dependent: an
 * administrator's Create page must NOT offer super_admin. Boots PostgreSQL 18.
 */

it('serialises the Admin/Users/Index list with the exact snake_case row contract and no secret leak', function (): void {
    $org = Organization::factory()->create(['name' => 'Sindicato Demo']);
    $admin = User::factory()->administrator()->forOrganization($org)->create();
    User::factory()->editor()->forOrganization($org)->create(['username' => 'rowuser']);

    actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Users/Index')
                ->has('users')
                ->has('can', fn (AssertableInertia $can): AssertableInertia => $can
                    ->hasAll(['create', 'assign_super_admin', 'manage_cross_org']))
                ->has('filters')
                ->has(
                    'users.0',
                    fn (AssertableInertia $row): AssertableInertia => $row->hasAll([
                        'id', 'username', 'name', 'email', 'role', 'role_label_key',
                        'organization_id', 'organization_name', 'is_demo',
                        'last_login_at', 'created_at',
                    ])->missing('password')->missing('remember_token'),
                ),
        );
});

it('marks an administrator as unable to assign super_admin or manage cross-org on the index `can` flags', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();

    actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('can.assign_super_admin', false)
                ->where('can.manage_cross_org', false),
        );
});

it('marks a super_admin as able to assign super_admin and manage cross-org on the index `can` flags', function (): void {
    $super = User::factory()->superAdmin()->create();

    actingAs($super)
        ->get(route('admin.users.index'))
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('can.assign_super_admin', true)
                ->where('can.manage_cross_org', true),
        );
});

it('offers an administrator only the sub-super_admin assignable roles on the Create page, and no org picker', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->administrator()->forOrganization($org)->create();

    actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('Admin/Users/Create')->has('assignable_roles');

            $roleValues = collect($page->toArray()['props']['assignable_roles'])
                ->pluck('value')
                ->all();

            expect($roleValues)->not->toContain(UserRole::SuperAdmin->value)
                ->and($roleValues)->toContain(UserRole::Editor->value);

            // An administrator has no cross-org org picker.
            expect($page->toArray()['props']['organizations'] ?? null)->toBeNull();
        });
});

it('offers a super_admin the super_admin role and the cross-org organizations picker on the Create page', function (): void {
    Organization::factory()->count(2)->create();
    $super = User::factory()->superAdmin()->create();

    actingAs($super)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('Admin/Users/Create');

            $roleValues = collect($page->toArray()['props']['assignable_roles'])
                ->pluck('value')
                ->all();

            expect($roleValues)->toContain(UserRole::SuperAdmin->value);

            expect($page->toArray()['props']['organizations'])->not->toBeNull();
        });
});

it('serialises the Admin/Users/Edit page with the target user + is_self + is_only_super_admin flags, no secret leak', function (): void {
    $super = User::factory()->superAdmin()->create(['username' => 'soleadmin']);

    actingAs($super)
        ->get(route('admin.users.edit', $super))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Users/Edit')
                ->has('user', fn (AssertableInertia $u): AssertableInertia => $u
                    ->hasAll(['id', 'username', 'name', 'email', 'role', 'organization_id', 'is_demo'])
                    ->missing('password')
                    ->missing('remember_token'))
                ->where('is_self', true)
                ->where('is_only_super_admin', true),
        );
});

it('flags is_self false and is_only_super_admin false when a super_admin edits a different lower-role user', function (): void {
    $org = Organization::factory()->create();
    $super = User::factory()->superAdmin()->create();
    $target = User::factory()->editor()->forOrganization($org)->create();

    actingAs($super)
        ->get(route('admin.users.edit', $target))
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->where('is_self', false)
                ->where('is_only_super_admin', false),
        );
});
