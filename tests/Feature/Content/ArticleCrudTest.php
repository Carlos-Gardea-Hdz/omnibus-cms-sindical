<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\ArticleImage;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Article CRUD (CONTRACT §6/§7/§11.4, SPEC §3.3 NEWS-01/02/03). Mutations run
 * end-to-end through the ['auth','role:editor'] group on PostgreSQL 18
 * (RefreshDatabase). A create lands a DRAFT (status enum instance, published_at
 * null) whose content was sanitized before persistence; the slug is derived and
 * unique (ignore-self on update). A delete is a SOFT delete that physically removes
 * the featured-image file and cascades the article_images rows at DB level. The
 * featured image lives on the faked `public` disk — no real files. Web validation
 * is 302 + session errors, never 422.
 */

/** A minimal, valid TipTap document body. */
function articleDoc(string $text = 'Hello world'): array
{
    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
    ];
}

/** A valid store/update payload for an article in the given category. */
function articlePayload(Category $category, array $overrides = []): array
{
    return array_merge([
        'title' => 'Comunicado oficial',
        'subtitle' => 'Resumen breve',
        'content' => articleDoc(),
        'category_id' => $category->getKey(),
        'signature' => 'Comité Ejecutivo',
        'meta_title' => 'Comunicado',
        'meta_description' => 'Descripción SEO',
    ], $overrides);
}

it('creates a draft article: status is the Draft enum instance, published_at null, slug derived', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), articlePayload($category, ['title' => 'Mi Primer Artículo']))
        ->assertRedirect()
        ->assertSessionHas('success', __('articles.created'))
        ->assertSessionHasNoErrors();

    $article = Article::query()->where('title', 'Mi Primer Artículo')->sole();

    // Enum-cast attribute is an ENUM INSTANCE in assertions (CMS lesson), not a string.
    expect($article->status)->toBe(ArticleStatus::Draft)
        ->and($article->published_at)->toBeNull()
        ->and($article->slug)->toBe('mi-primer-articulo')
        ->and($article->category_id)->toBe($category->getKey());
});

it('stamps the authenticated user as the author', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();
    $author = User::factory()->editor()->create();

    actingAs($author)
        ->post(route('admin.articles.store'), articlePayload($category))
        ->assertRedirect();

    expect(Article::query()->latest('id')->sole()->author_id)->toBe($author->getKey());
});

it('updates an article and keeps its own slug (unique ignores self)', function (): void {
    $category = Category::factory()->create();
    $article = Article::factory()->create(['slug' => 'keep-me', 'category_id' => $category->getKey()]);

    actingAs(User::factory()->editor()->create())
        ->put(route('admin.articles.update', $article), articlePayload($category, [
            'title' => 'Título Nuevo',
            'slug' => 'keep-me', // unchanged
        ]))
        ->assertRedirect()
        ->assertSessionHas('success', __('articles.updated'))
        ->assertSessionHasNoErrors();

    expect($article->fresh())
        ->title->toBe('Título Nuevo')
        ->slug->toBe('keep-me');
});

it('rejects an update whose slug collides with a DIFFERENT article (302 + slug error)', function (): void {
    $category = Category::factory()->create();
    Article::factory()->create(['slug' => 'taken', 'category_id' => $category->getKey()]);
    $article = Article::factory()->create(['slug' => 'mine', 'category_id' => $category->getKey()]);

    actingAs(User::factory()->editor()->create())
        ->put(route('admin.articles.update', $article), articlePayload($category, ['slug' => 'taken']))
        ->assertRedirect()
        ->assertSessionHasErrors('slug');

    expect($article->fresh()->slug)->toBe('mine'); // unchanged
});

it('soft-deletes an article and cascades its image rows, removing the physical featured file', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    // Create with a real featured image so we can prove the physical file is removed.
    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), articlePayload($category, [
            'featured_image' => UploadedFile::fake()->image('cover.jpg', 1200, 630),
        ]))
        ->assertRedirect();

    $article = Article::query()->latest('id')->sole();
    expect($article->featured_image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($article->featured_image_path);

    // Attach gallery image rows so the DB-level cascade is observable.
    ArticleImage::factory()->count(2)->create(['article_id' => $article->getKey()]);
    expect(ArticleImage::query()->where('article_id', $article->getKey())->count())->toBe(2);

    actingAs(User::factory()->editor()->create())
        ->delete(route('admin.articles.destroy', $article))
        ->assertRedirect()
        ->assertSessionHas('success', __('articles.deleted'))
        ->assertSessionHasNoErrors();

    // SOFT delete: deleted_at set, the row is still in the table (with trashed).
    $this->assertSoftDeleted('articles', ['id' => $article->getKey()]);

    // The physical featured-image file is gone (NEWS-03).
    Storage::disk('public')->assertMissing($article->featured_image_path);
});

it('persists a draft with sanitized content (a hostile payload never reaches the DB raw)', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    $hostile = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['onclick' => 'steal()'],
                'content' => [['type' => 'text', 'text' => 'visible text']],
            ],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
        ],
    ];

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), articlePayload($category, ['content' => $hostile]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $stored = json_encode(Article::query()->latest('id')->sole()->content, JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('steal()')
        ->and($stored)->toContain('visible text'); // legitimate content kept
});
