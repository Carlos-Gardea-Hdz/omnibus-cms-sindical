<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Organization CRUD (CONTRACT §8/§9/§16, SPEC §3.2 ORG-01, §11.4). Every mutation
 * runs end-to-end through the role:super_admin admin/organizations resource on
 * PostgreSQL 18 (RefreshDatabase, never SQLite). Web validation surfaces as 302 +
 * session errors (Spatie Data via the controller signature) — NEVER 422. A logo is
 * REQUIRED on create (ORG-01). The slug is derived + unique, ignore-self on update.
 * Delete is the graceful restrict-delete: an organization with ≥1 branch must fail
 * with a 302 + the 'organization' error and SURVIVE (never a 500 — the Action
 * pre-checks before the restrict FK on branches.organization_id can trip).
 */

/** A super_admin actor (the only role that may manage organizations). */
function orgAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/** A valid create payload for an organization in the given municipality. */
function organizationPayload(Municipality $municipality, array $overrides = []): array
{
    return array_merge([
        'name' => 'Sindicato Demo',
        'municipality_id' => $municipality->getKey(),
        'registered_at' => '2024-01-15',
        'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
    ], $overrides);
}

it('creates an organization on the happy path: slug derived, logo stored, 302 + created flash', function (): void {
    Storage::fake('public');
    $municipality = Municipality::factory()->create();

    actingAs(orgAdmin())
        ->post(route('admin.organizations.store'), organizationPayload($municipality, ['name' => 'Unión General']))
        ->assertRedirect()
        ->assertSessionHas('success', __('organizations.created'))
        ->assertSessionHasNoErrors();

    $organization = Organization::query()->where('name', 'Unión General')->sole();

    expect($organization->slug)->toBe('union-general')
        ->and($organization->municipality_id)->toBe($municipality->getKey())
        ->and($organization->logo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($organization->logo_path);
});

it('requires a logo on create (ORG-01): 302 + logo error, nothing created', function (): void {
    Storage::fake('public');
    $municipality = Municipality::factory()->create();

    $payload = organizationPayload($municipality);
    unset($payload['logo']);

    actingAs(orgAdmin())
        ->post(route('admin.organizations.store'), $payload)
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('logo');

    expect(Organization::query()->count())->toBe(0);
});

it('updates an organization keeping its own slug (unique ignores self)', function (): void {
    Storage::fake('public');
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create([
        'name' => 'Antes',
        'slug' => 'keep-slug',
    ]);

    actingAs(orgAdmin())
        ->put(route('admin.organizations.update', $organization), [
            'name' => 'Después',
            'slug' => 'keep-slug', // unchanged — must not trip its own unique row
            'municipality_id' => $municipality->getKey(),
            'registered_at' => '2024-02-01',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', __('organizations.updated'))
        ->assertSessionHasNoErrors();

    expect($organization->fresh())
        ->name->toBe('Después')
        ->slug->toBe('keep-slug');
});

it('rejects an update whose slug collides with a DIFFERENT organization (302 + slug error)', function (): void {
    Storage::fake('public');
    $municipality = Municipality::factory()->create();
    Organization::factory()->for($municipality)->create(['slug' => 'taken-slug']);
    $organization = Organization::factory()->for($municipality)->create(['slug' => 'mine-slug']);

    actingAs(orgAdmin())
        ->put(route('admin.organizations.update', $organization), [
            'name' => 'Cualquiera',
            'slug' => 'taken-slug', // owned by the other org
            'municipality_id' => $municipality->getKey(),
            'registered_at' => '2024-03-01',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('slug')
        ->assertSessionHasErrors(['slug' => __('organizations.error.slug_taken')]);

    expect($organization->fresh()->slug)->toBe('mine-slug'); // unchanged
});

it('refuses to delete an organization that still has a branch (302, in-use error, row survives, NOT 500)', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();
    Branch::factory()->for($organization)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasErrors(['organization' => __('organizations.error.has_branches')]);

    // The Action pre-check fired before the restrict FK could be tripped: the org is
    // untouched (not even soft-deleted) and the user never saw a 500.
    expect(Organization::query()->whereKey($organization->getKey())->withTrashed()->whereNull('deleted_at')->exists())->toBeTrue();
});

it('soft-deletes an organization that has no branches (302 + deleted flash)', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHas('success', __('organizations.deleted'))
        ->assertSessionHasNoErrors();

    $this->assertSoftDeleted('organizations', ['id' => $organization->getKey()]);
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * BLOCKER 2 — the in-use pre-check covers EVERY restrict referrer, not just branches.
 * directors / users / articles / job_postings / contact_messages / page_views /
 * daily_snapshots all carry `organization_id ... restrictOnDelete()`. Deleting an org
 * with any of them (but NO branches) USED to slip past the branch-only pre-check, trip
 * the restrict FK, and 500. These prove each referrer (with no branch present) now yields
 * the graceful 302 + 'organization' error and the org survives — never a 500. (Member is
 * SET NULL, so it must NOT block the delete.)
 * ─────────────────────────────────────────────────────────────────────────────
 */

it('refuses (gracefully, 302) to delete an org that has a DIRECTOR but no branch — never a 500', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();
    Director::factory()->forOrganization($organization)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasErrors(['organization' => __('organizations.error.has_branches')]);

    // The org survived (not even soft-deleted) — the pre-check fired before the FK could trip.
    expect(Organization::query()->whereKey($organization->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('refuses (gracefully, 302) to delete an org that has a USER but no branch — never a 500', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();
    User::factory()->manager()->forOrganization($organization)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasErrors(['organization' => __('organizations.error.has_branches')]);

    expect(Organization::query()->whereKey($organization->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('refuses (gracefully, 302) to delete an org that has an ARTICLE but no branch — never a 500', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();
    Article::factory()->forOrganization($organization)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasErrors(['organization' => __('organizations.error.has_branches')]);

    expect(Organization::query()->whereKey($organization->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('refuses (gracefully, 302) to delete an org that has a TRASHED article but no branch (trashed rows still hold the FK)', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();
    $article = Article::factory()->forOrganization($organization)->create();
    $article->delete(); // soft-deleted — the FK is still physically held

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHasErrors(['organization' => __('organizations.error.has_branches')]);

    expect(Organization::query()->whereKey($organization->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('still soft-deletes cleanly an org with ZERO referrers (the happy path is unbroken)', function (): void {
    $municipality = Municipality::factory()->create();
    $organization = Organization::factory()->for($municipality)->create();

    actingAs(orgAdmin())
        ->delete(route('admin.organizations.destroy', $organization))
        ->assertRedirect()
        ->assertSessionHas('success', __('organizations.deleted'))
        ->assertSessionHasNoErrors();

    $this->assertSoftDeleted('organizations', ['id' => $organization->getKey()]);
});
