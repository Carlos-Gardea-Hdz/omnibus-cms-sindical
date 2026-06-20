<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\RepresentativeShift;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Representative CRUD (CONTRACT §8/§16, SPEC §3.2 ORG-03). The shift is the
 * RepresentativeShift enum (cast on the model — assertions compare the ENUM
 * INSTANCE, never a string, per the CMS lesson); is_coordinator is an honored
 * boolean. The branches/representatives gate is manager+ and carries 'org.scope',
 * so the acting manager is created WITH the same organization its representative
 * lives in (else the confined-null scope would 404 the route-model binding). Runs on
 * PostgreSQL 18 (RefreshDatabase).
 */

/** A manager confined to the given organization (so org.scope resolves its rows). */
function repManager(Organization $organization): User
{
    return User::factory()->manager()->forOrganization($organization)->create();
}

/** A valid create payload for a representative of the given org + branch. */
function representativePayload(Organization $organization, Branch $branch, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->getKey(),
        'branch_id' => $branch->getKey(),
        'first_name' => 'Carla',
        'last_name' => 'Demo',
        'shift' => RepresentativeShift::Morning->value,
        'is_coordinator' => false,
    ], $overrides);
}

it('creates a representative: shift cast to the enum instance, is_coordinator honored (302 + created)', function (): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(repManager($organization))
        ->post(route('admin.representatives.store'), representativePayload($organization, $branch, [
            'first_name' => 'Coordinadora',
            'shift' => RepresentativeShift::Evening->value,
            'is_coordinator' => true,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', __('representatives.created'))
        ->assertSessionHasNoErrors();

    $representative = Representative::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Coordinadora')->sole();

    // Enum-cast attribute is an ENUM INSTANCE, never a string.
    expect($representative->shift)->toBe(RepresentativeShift::Evening)
        ->and($representative->is_coordinator)->toBeTrue()
        ->and($representative->organization_id)->toBe($organization->getKey())
        ->and($representative->branch_id)->toBe($branch->getKey());
});

it('updates a representative within the acting org', function (): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $representative = Representative::factory()->for($organization)->for($branch)->create([
        'first_name' => 'Antes',
        'shift' => RepresentativeShift::Morning,
    ]);

    actingAs(repManager($organization))
        ->put(route('admin.representatives.update', $representative), representativePayload($organization, $branch, [
            'first_name' => 'Después',
            'shift' => RepresentativeShift::Night->value,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', __('representatives.updated'))
        ->assertSessionHasNoErrors();

    expect($representative->fresh())
        ->first_name->toBe('Después')
        ->shift->toBe(RepresentativeShift::Night);
});

it('soft-deletes a representative within the acting org (302 + deleted flash)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $representative = Representative::factory()->for($organization)->for($branch)->create();

    actingAs(repManager($organization))
        ->delete(route('admin.representatives.destroy', $representative))
        ->assertRedirect()
        ->assertSessionHas('success', __('representatives.deleted'))
        ->assertSessionHasNoErrors();

    $this->assertSoftDeleted('representatives', ['id' => $representative->getKey()]);
});
