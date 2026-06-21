<?php

declare(strict_types=1);

use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the admin contact inbox (CONTRACT §6/§12.7, SPEC §7.3, §10.2). The
 * /admin/contacts route sits behind ['auth','role:manager','org.scope'] — Manager+ (level
 * 2), the SAME rung as members/branches/representatives. Crucially an EDITOR (level 1) has
 * NO contact access (§10.2: Contacts editor = "—") and is 403'd, unlike the Jobs admin
 * which is editor-gated. A guest is bounced to /login (302, never 403). The PUBLIC
 * submission route is open (no auth) — covered in ContactSubmissionTest. The 'org.scope'
 * middleware never aborts — access is owned by 'role'. Runs on PostgreSQL 18
 * (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('redirects a guest to login (302, never 403) on the contacts index', function (): void {
    get(route('admin.contacts.index'))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
});

it('FORBIDS an editor on /admin/contacts with a 403 (editors have no contact access, §10.2)', function (): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();

    actingAs($editor)
        ->get(route('admin.contacts.index'))
        ->assertForbidden();
});

it('lets every role at or above manager reach the contacts index (200, never 403, never login)', function (string $role): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->{$role}()->forOrganization($organization)->create();

    $response = actingAs($user)->get(route('admin.contacts.index'));

    expect($response->getStatusCode())->not->toBe(403);
    expect($response->headers->get('Location'))->not->toBe(route('login'));
    $response->assertOk();
})->with([
    'manager' => ['manager'],
    'administrator' => ['administrator'],
    'super_admin' => ['superAdmin'],
]);
