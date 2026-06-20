<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Category catalog CRUD (CONTRACT §6/§7/§11.3, SPEC §3.3 CAT-01/CAT-02). Every
 * mutation runs end-to-end through the ['auth','role:editor','org.scope'] route
 * group on PostgreSQL 18 (RefreshDatabase, never SQLite). Web validation surfaces as
 * 302 + session errors (the Spatie-Data-via-method-signature convention) — NEVER 422.
 * Deleting a category an Article still references must fail GRACEFULLY: 302 + the
 * CategoryInUseException 'category' error, the row preserved, NEVER a 500 (the
 * restrict FK is never tripped because the Action pre-checks).
 *
 * SLICE-003 RETROFIT (§17 firewall): the editor group now carries 'org.scope', and
 * Category is a SHARED catalog (NOT org-scoped) so the slug/CRUD tests stand as-is.
 * The ONE place confinement bites is the in-use pre-check — DeleteCategoryAction's
 * `Article::where('category_id')->exists()` runs the (now globally org-scoped)
 * Article query inside the confined editor's request. So the referencing-article
 * fixtures in the two in-use tests are pinned to the editor's org (else a confined
 * editor's scoped query would not see the article and would wrongly allow the
 * delete). This proves the in-use guard fires WITHIN the acting editor's org.
 */

function editor(): User
{
    return User::factory()->editor()->create();
}

/** An editor confined to the given org (org.scope confines its Article queries). */
function categoryOrgEditor(Organization $organization): User
{
    return User::factory()->editor()->forOrganization($organization)->create();
}

it('creates a category on the happy path, deriving the slug', function (): void {
    actingAs(editor())
        ->post(route('admin.categories.store'), [
            'name' => 'Noticias Sindicales',
            'description' => 'Comunicados oficiales',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', __('categories.created'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('categories', [
        'name' => 'Noticias Sindicales',
        'slug' => 'noticias-sindicales',
        'description' => 'Comunicados oficiales',
    ]);
});

it('updates a category keeping its own slug (unique ignores self)', function (): void {
    $category = Category::factory()->create(['name' => 'Antes', 'slug' => 'keep-slug']);

    actingAs(editor())
        ->put(route('admin.categories.update', $category), [
            'name' => 'Después',
            'slug' => 'keep-slug', // unchanged — must not trip its own unique row
        ])
        ->assertRedirect()
        ->assertSessionHas('success', __('categories.updated'))
        ->assertSessionHasNoErrors();

    expect($category->fresh())
        ->name->toBe('Después')
        ->slug->toBe('keep-slug');
});

it('rejects a create whose slug collides with another category (302 + slug error, never 500)', function (): void {
    Category::factory()->create(['slug' => 'taken-slug', 'name' => 'Existente']);

    actingAs(editor())
        ->post(route('admin.categories.store'), [
            'name' => 'Otra',
            'slug' => 'taken-slug',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('slug')
        ->assertStatus(302);

    expect(Category::query()->where('slug', 'taken-slug')->count())->toBe(1);
});

it('rejects an update whose slug collides with a DIFFERENT category (302 + slug error)', function (): void {
    Category::factory()->create(['slug' => 'taken-slug']);
    $category = Category::factory()->create(['slug' => 'mine-slug']);

    actingAs(editor())
        ->put(route('admin.categories.update', $category), [
            'name' => 'Cualquiera',
            'slug' => 'taken-slug', // owned by the other row
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('slug');

    expect($category->fresh()->slug)->toBe('mine-slug'); // unchanged
});

it('rejects invalid create input with 302 + session errors and persists nothing', function (array $payload, string $field): void {
    actingAs(editor())
        ->post(route('admin.categories.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasErrors($field)
        ->assertStatus(302);

    expect(Category::query()->where('name', $payload['name'] ?? '__none__')->exists())->toBeFalse();
})->with([
    'missing name' => [['description' => 'sin nombre'], 'name'],
    'over-max name (>30)' => [['name' => str_repeat('A', 31)], 'name'],
    'over-max description (>250)' => [['name' => 'OK', 'description' => str_repeat('d', 251)], 'description'],
]);

it('deletes a category that nothing references', function (): void {
    $category = Category::factory()->create();

    actingAs(editor())
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHas('success', __('categories.deleted'))
        ->assertSessionHasNoErrors();

    expect(Category::query()->whereKey($category->getKey())->exists())->toBeFalse();
});

it('refuses to delete a category an article references (302, in-use error, row survives, NOT 500)', function (): void {
    $org = Organization::factory()->createOne();
    $category = Category::factory()->create();
    Article::factory()->forOrganization($org)->create(['category_id' => $category->getKey()]);

    actingAs(categoryOrgEditor($org))
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHasErrors('category');

    // The row is still present (never hard-deleted) and no 500 occurred — the
    // Action pre-check fired before the restrict FK could be tripped.
    expect(Category::query()->whereKey($category->getKey())->exists())->toBeTrue();
});

it('reports the in-use failure with the localized categories.error.in_use message', function (): void {
    $org = Organization::factory()->createOne();
    $category = Category::factory()->create();
    Article::factory()->forOrganization($org)->create(['category_id' => $category->getKey()]);

    actingAs(categoryOrgEditor($org))
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHasErrors(['category' => __('categories.error.in_use')]);
});
