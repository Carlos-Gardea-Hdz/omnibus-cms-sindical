<?php

declare(strict_types=1);

use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Municipality catalog CRUD (CONTRACT §8/§9/§16, SPEC §3.2 ORG-05, §6.3.1). The
 * municipalities table is a hard-delete reference catalog (NO SoftDeletes) guarded
 * by a graceful restrict-delete pre-check — exactly the UNIGES Department pattern.
 * Every mutation runs through the role:administrator admin/municipalities resource
 * on PostgreSQL 18 (RefreshDatabase). A municipality referenced by ≥1 organization
 * must fail with a 302 + the 'municipality' error and SURVIVE — never a 500 (the
 * Action pre-checks before the restrict FK on organizations.municipality_id trips).
 */

/** An administrator actor (the municipalities gate is administrator+). */
function municipalityAdmin(): User
{
    return User::factory()->administrator()->create();
}

it('creates a municipality on the happy path (302 + created flash)', function (): void {
    actingAs(municipalityAdmin())
        ->post(route('admin.municipalities.store'), [
            'name' => 'Ciudad Norte',
            'state' => 'Estado Demo',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', __('municipalities.created'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('municipalities', [
        'name' => 'Ciudad Norte',
        'state' => 'Estado Demo',
    ]);
});

it('refuses to delete a municipality an organization references (302, in-use error, row survives, NOT 500)', function (): void {
    $municipality = Municipality::factory()->create();
    Organization::factory()->for($municipality)->create();

    actingAs(municipalityAdmin())
        ->delete(route('admin.municipalities.destroy', $municipality))
        ->assertRedirect()
        ->assertSessionHasErrors(['municipality' => __('municipalities.error.in_use')]);

    // Hard-delete catalog: the row must still physically exist (pre-check stopped it).
    expect(Municipality::query()->whereKey($municipality->getKey())->exists())->toBeTrue();
});

it('deletes a municipality that nothing references', function (): void {
    $municipality = Municipality::factory()->create();

    actingAs(municipalityAdmin())
        ->delete(route('admin.municipalities.destroy', $municipality))
        ->assertRedirect()
        ->assertSessionHas('success', __('municipalities.deleted'))
        ->assertSessionHasNoErrors();

    // No SoftDeletes on municipalities — the row is physically gone.
    expect(Municipality::query()->whereKey($municipality->getKey())->exists())->toBeFalse();
});

it('rejects invalid municipality input with 302 + session errors and persists nothing', function (array $payload, string $field): void {
    actingAs(municipalityAdmin())
        ->post(route('admin.municipalities.store'), $payload)
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors($field);

    expect(Municipality::query()->where('name', $payload['name'] ?? '__none__')->exists())->toBeFalse();
})->with([
    'missing name' => [['state' => 'Estado Demo'], 'name'],
    'missing state' => [['name' => 'Solo Nombre'], 'state'],
    'over-max name (>100)' => [['name' => str_repeat('A', 101), 'state' => 'Estado Demo'], 'name'],
    'over-max state (>100)' => [['name' => 'OK', 'state' => str_repeat('S', 101)], 'state'],
]);
