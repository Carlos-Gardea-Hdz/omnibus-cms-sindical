<?php

declare(strict_types=1);

use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * One director per organization (CONTRACT §8/§16, SPEC §3.2 ORG-04). Two layers,
 * both asserted so neither can silently regress:
 *
 *   1. The Action guard — creating a SECOND director for an org that already has one
 *      fails GRACEFULLY: DirectorAlreadyAssignedException → 302 + the 'director'
 *      error; nothing is created; NEVER a 500.
 *   2. The DB backstop — a RAW second insert (bypassing the Action) raises a unique
 *      constraint violation, PROVING the directors.organization_id unique index
 *      physically exists. If a migration ever drops the unique index, this goes red.
 *
 * The directors gate is administrator+. Runs on PostgreSQL 18 (RefreshDatabase).
 */

/** An administrator actor (the directors gate is administrator+). */
function directorAdmin(): User
{
    return User::factory()->administrator()->create();
}

/** A valid create payload for a director of the given organization. */
function directorPayload(Organization $organization, array $overrides = []): array
{
    return array_merge([
        'organization_id' => $organization->getKey(),
        'first_name' => 'Ana',
        'last_name' => 'Demo',
    ], $overrides);
}

it('creates the first director and wires organizations.director_id (302 + created flash)', function (): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();

    actingAs(directorAdmin())
        ->post(route('admin.directors.store'), directorPayload($organization))
        ->assertRedirect()
        ->assertSessionHas('success', __('directors.created'))
        ->assertSessionHasNoErrors();

    $director = Director::query()->where('organization_id', $organization->getKey())->sole();

    expect($director->first_name)->toBe('Ana')
        ->and($organization->fresh()->director_id)->toBe($director->getKey());
});

it('refuses a second director for the same org via the Action guard (302 + director error, NEVER 500)', function (): void {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    Director::factory()->for($organization)->create();

    actingAs(directorAdmin())
        ->post(route('admin.directors.store'), directorPayload($organization, ['first_name' => 'Beto']))
        ->assertRedirect()
        ->assertSessionHasErrors(['director' => __('directors.error.already_assigned')]);

    // Nothing extra created — still exactly one director for the org.
    expect(Director::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
});

it('proves the DB unique index exists: a RAW second insert raises a unique violation', function (): void {
    $organization = Organization::factory()->create();
    Director::factory()->for($organization)->create();

    // Bypass the Action entirely — force a duplicate organization_id straight into
    // the table. The directors.organization_id unique index must reject it.
    expect(fn (): Director => Director::factory()->for($organization)->create())
        ->toThrow(QueryException::class);
});
