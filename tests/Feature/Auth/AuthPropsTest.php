<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Runtime contract test for the Auth Inertia pages (CONTRACT §11). Inertia props
 * are untyped at runtime, so the static gates cannot catch a controller that
 * serialises a different shape than the React page consumes. These tests lock
 * the EXACT snake_case prop shape each page receives. Runs against PostgreSQL 18
 * via RefreshDatabase.
 *
 *   Auth/Login    → no page-specific props (errors arrive via the shared bag).
 *   Admin/Dashboard → { username, role, role_label_key } and nothing else leaked.
 */

it('renders the Auth/Login component for a guest with no page-specific props', function (): void {
    get(route('login'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Auth/Login')
                ->missing('username')
                ->missing('password')
                ->missing('role'),
        );
});

it('renders the Admin/Dashboard component with the exact snake_case prop contract', function (): void {
    $user = User::factory()->superAdmin()->create(['username' => 'boss']);

    actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Dashboard')
                ->where('username', 'boss')
                ->where('role', 'super_admin')
                ->where('role_label_key', 'role.super_admin')
                ->hasAll(['username', 'role', 'role_label_key']),
        );
});

it('serialises the role backing value and its label key for a non-super-admin role', function (): void {
    $user = User::factory()->editor()->create(['username' => 'writer']);

    actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Admin/Dashboard')
                ->where('username', 'writer')
                ->where('role', 'editor')
                ->where('role_label_key', 'role.editor'),
        );
});
