<?php

declare(strict_types=1);

use App\Domain\Membership\Models\Member;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the Membership admin routes (CONTRACT §8/§14, SPEC §7.3 §10.2). The
 * /admin/members group sits behind ['auth','role:manager','org.scope'] — Manager+
 * (level 2). Crucially an EDITOR (level 1) has NO member access (§10.2) and is 403'd,
 * unlike the Jobs admin which is editor-gated. A guest is bounced to /login (302, never
 * 403). The PUBLIC registration routes are open (no auth) — covered in
 * MembershipRegistrationTest. The 'org.scope' middleware never aborts — access is owned
 * by 'role'. Runs on PostgreSQL 18 (RefreshDatabase). Fixtures are FICTIONAL.
 */

it('redirects a guest to login (302, never 403) on the members index', function (): void {
    get(route('admin.members.index'))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
});

it('redirects a guest POSTing approve/reject to login (302, never 403)', function (string $action): void {
    $member = Member::factory()->pending()->create();

    $this->post(route("admin.members.{$action}", $member))
        ->assertRedirect(route('login'))
        ->assertStatus(302);
})->with([
    'approve' => ['approve'],
    'reject' => ['reject'],
]);

it('FORBIDS an editor on /admin/members with a 403 (editors have no member access, §10.2)', function (): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();

    actingAs($editor)
        ->get(route('admin.members.index'))
        ->assertForbidden();
});

it('forbids an editor approving/rejecting a member with a 403', function (string $action): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->editor()->forOrganization($organization)->create();
    $member = Member::factory()->forOrganization($organization)->pending()->create();

    actingAs($editor)
        ->post(route("admin.members.{$action}", $member))
        ->assertForbidden();
})->with([
    'approve' => ['approve'],
    'reject' => ['reject'],
]);

it('lets every role at or above manager reach the members index (200, never 403, never login)', function (string $role): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->{$role}()->forOrganization($organization)->create();

    $response = actingAs($user)->get(route('admin.members.index'));

    expect($response->getStatusCode())->not->toBe(403);
    expect($response->headers->get('Location'))->not->toBe(route('login'));
    $response->assertOk();
})->with([
    'manager' => ['manager'],
    'administrator' => ['administrator'],
    'super_admin' => ['superAdmin'],
]);
