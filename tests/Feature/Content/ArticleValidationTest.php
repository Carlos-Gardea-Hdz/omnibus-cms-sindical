<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * ArticleData validation (CONTRACT §4/§11.5, SPEC §3.3 NEWS-01). Validation is the
 * single source of truth via Spatie Data on the controller method signature, so a
 * web request that fails ALWAYS surfaces as a 302 redirect-back with session errors
 * — NEVER a 422 (the cardinal CMS web-validation rule). content must be a non-empty
 * TipTap doc ({type:'doc',content:[…]}); category_id must Exist; title/subtitle are
 * length-bounded. Runs against PostgreSQL 18 via RefreshDatabase.
 *
 * SLICE-003 RETROFIT (§17 firewall): the store request runs through 'org.scope', so
 * the "nothing persisted" assertions read with withoutGlobalScopes() — they assert
 * the PHYSICAL absence of a row, not merely that the leftover null-org confinement
 * hides it. This makes the guard genuinely falsifiable under the new scope.
 */

/** A valid TipTap doc body for the fields that are NOT under test. */
function validDoc(): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ok']]]]];
}

/** A full valid payload; the per-case dataset overwrites the field under test. */
function baseArticlePayload(int $categoryId): array
{
    return [
        'title' => 'Título válido',
        'subtitle' => 'Subtítulo',
        'content' => validDoc(),
        'category_id' => $categoryId,
    ];
}

it('rejects invalid create input with a 302 + session error, NEVER a 422, and persists nothing', function (Closure $mutate, string $field): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    $payload = $mutate(baseArticlePayload($category->getKey()));

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), $payload)
        ->assertRedirect()                 // 302, the web convention
        ->assertStatus(302)                // explicitly NOT 422
        ->assertSessionHasErrors($field);

    expect(Article::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'missing title' => [
        fn (array $p): array => array_diff_key($p, ['title' => null]),
        'title',
    ],
    'over-length title (>150)' => [
        fn (array $p): array => [...$p, 'title' => str_repeat('A', 151)],
        'title',
    ],
    'over-length subtitle (>200)' => [
        fn (array $p): array => [...$p, 'subtitle' => str_repeat('S', 201)],
        'subtitle',
    ],
    'missing content' => [
        fn (array $p): array => array_diff_key($p, ['content' => null]),
        'content',
    ],
    'malformed content (empty doc body)' => [
        fn (array $p): array => [...$p, 'content' => ['type' => 'doc', 'content' => []]],
        'content',
    ],
    'malformed content (wrong root type)' => [
        fn (array $p): array => [...$p, 'content' => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]],
        'content',
    ],
    'non-existent category_id' => [
        fn (array $p): array => [...$p, 'category_id' => 999_999],
        'category_id',
    ],
    'missing category_id' => [
        fn (array $p): array => array_diff_key($p, ['category_id' => null]),
        'category_id',
    ],
]);

it('rejects an over-length slug (>180) with a 302 + session error', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), [
            ...baseArticlePayload($category->getKey()),
            'slug' => str_repeat('a', 181),
        ])
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('slug');
});

it('rejects a non-image featured upload with a 302 + session error', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), [
            ...baseArticlePayload($category->getKey()),
            'featured_image' => Illuminate\Http\UploadedFile::fake()->create('virus.exe', 50, 'application/octet-stream'),
        ])
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('featured_image');

    expect(Article::query()->withoutGlobalScopes()->count())->toBe(0);
});
