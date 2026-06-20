<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\RepresentativeShift;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Org-domain DTO validation (CONTRACT §6/§16, SPEC §3.2). Every Org DTO is validated
 * via Spatie Data on the controller signature, so a failing WEB request ALWAYS
 * surfaces as a 302 redirect-back with session errors — NEVER a 422 (the cardinal
 * CMS web-validation rule). This covers each entity's required fields, length bounds,
 * the Exists() FK rules, and the RepresentativeShift enum guard. Runs on PostgreSQL
 * 18 (RefreshDatabase). The acting user is super_admin (unconfined) so a bad payload
 * fails on VALIDATION, not on org confinement.
 */

function validationAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('rejects an organization create with no logo or bad refs: 302, never 422, nothing created', function (Closure $payload, string $field): void {
    Storage::fake('public');
    $municipality = Municipality::factory()->create();

    actingAs(validationAdmin())
        ->post(route('admin.organizations.store'), $payload($municipality))
        ->assertRedirect()
        ->assertStatus(302) // explicitly NOT 422
        ->assertSessionHasErrors($field);

    expect(Organization::query()->count())->toBe(0);
})->with([
    'missing name' => [
        fn (Municipality $m): array => ['municipality_id' => $m->getKey(), 'registered_at' => '2024-01-01', 'logo' => UploadedFile::fake()->image('l.png')],
        'name',
    ],
    'over-max name (>100)' => [
        fn (Municipality $m): array => ['name' => str_repeat('A', 101), 'municipality_id' => $m->getKey(), 'registered_at' => '2024-01-01', 'logo' => UploadedFile::fake()->image('l.png')],
        'name',
    ],
    'non-existent municipality_id' => [
        fn (Municipality $m): array => ['name' => 'OK', 'municipality_id' => 999_999, 'registered_at' => '2024-01-01', 'logo' => UploadedFile::fake()->image('l.png')],
        'municipality_id',
    ],
    'missing registered_at' => [
        fn (Municipality $m): array => ['name' => 'OK', 'municipality_id' => $m->getKey(), 'logo' => UploadedFile::fake()->image('l.png')],
        'registered_at',
    ],
    'missing logo (ORG-01)' => [
        fn (Municipality $m): array => ['name' => 'OK', 'municipality_id' => $m->getKey(), 'registered_at' => '2024-01-01'],
        'logo',
    ],
]);

it('rejects a branch create with bad input: 302, never 422', function (Closure $payload, string $field): void {
    $organization = Organization::factory()->create();

    actingAs(validationAdmin())
        ->post(route('admin.branches.store'), $payload($organization))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors($field);
})->with([
    'missing organization_id' => [
        fn (Organization $o): array => ['name' => 'Sucursal', 'location' => 'Centro'],
        'organization_id',
    ],
    'non-existent organization_id' => [
        fn (Organization $o): array => ['organization_id' => 999_999, 'name' => 'Sucursal', 'location' => 'Centro'],
        'organization_id',
    ],
    'missing name' => [
        fn (Organization $o): array => ['organization_id' => $o->getKey(), 'location' => 'Centro'],
        'name',
    ],
    'over-max location (>100)' => [
        fn (Organization $o): array => ['organization_id' => $o->getKey(), 'name' => 'Sucursal', 'location' => str_repeat('L', 101)],
        'location',
    ],
]);

it('rejects a director create with bad input: 302, never 422', function (Closure $payload, string $field): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();

    actingAs(validationAdmin())
        ->post(route('admin.directors.store'), $payload($organization))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors($field);

    expect(Director::query()->count())->toBe(0);
})->with([
    'non-existent organization_id' => [
        fn (Organization $o): array => ['organization_id' => 999_999, 'first_name' => 'A', 'last_name' => 'B'],
        'organization_id',
    ],
    'missing first_name' => [
        fn (Organization $o): array => ['organization_id' => $o->getKey(), 'last_name' => 'B'],
        'first_name',
    ],
    'over-max last_name (>100)' => [
        fn (Organization $o): array => ['organization_id' => $o->getKey(), 'first_name' => 'A', 'last_name' => str_repeat('B', 101)],
        'last_name',
    ],
]);

it('rejects a representative create with bad input (incl. an invalid shift): 302, never 422', function (Closure $payload, string $field): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(validationAdmin())
        ->post(route('admin.representatives.store'), $payload($organization, $branch))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors($field);

    expect(Representative::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
})->with([
    'non-existent branch_id' => [
        fn (Organization $o, Branch $b): array => ['organization_id' => $o->getKey(), 'branch_id' => 999_999, 'first_name' => 'A', 'last_name' => 'B', 'shift' => RepresentativeShift::Morning->value],
        'branch_id',
    ],
    'missing organization_id' => [
        fn (Organization $o, Branch $b): array => ['branch_id' => $b->getKey(), 'first_name' => 'A', 'last_name' => 'B', 'shift' => RepresentativeShift::Morning->value],
        'organization_id',
    ],
    'invalid shift value' => [
        fn (Organization $o, Branch $b): array => ['organization_id' => $o->getKey(), 'branch_id' => $b->getKey(), 'first_name' => 'A', 'last_name' => 'B', 'shift' => 'siesta'],
        'shift',
    ],
    'missing shift' => [
        fn (Organization $o, Branch $b): array => ['organization_id' => $o->getKey(), 'branch_id' => $b->getKey(), 'first_name' => 'A', 'last_name' => 'B'],
        'shift',
    ],
]);

it('rejects a municipality create with bad input: 302, never 422', function (array $payload, string $field): void {
    actingAs(validationAdmin())
        ->post(route('admin.municipalities.store'), $payload)
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors($field);
})->with([
    'missing name' => [['state' => 'Estado'], 'name'],
    'missing state' => [['name' => 'Ciudad'], 'state'],
]);
