<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Runtime prop-contract tests for the Content Inertia pages (CONTRACT §8/§11.9).
 * Inertia props are untyped at runtime, so the static gates cannot catch a
 * controller that serialises a different shape than the React page consumes. These
 * lock the EXACT snake_case prop shape each page receives — a future controller/
 * page drift fails CI. Runs against PostgreSQL 18 (RefreshDatabase) as an editor
 * (manager for the gated pages where needed).
 */

function contentEditor(): User
{
    return User::factory()->editor()->create();
}

it('locks the Categories/Index prop contract (snake_case + articles_count)', function (): void {
    $category = Category::factory()->create(['name' => 'Comunicados', 'slug' => 'comunicados', 'description' => 'Oficiales']);
    Article::factory()->count(2)->create(['category_id' => $category->getKey()]);

    actingAs(contentEditor())
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Categories/Index')
                ->has('categories', 1)
                ->where('categories.0.id', $category->getKey())
                ->where('categories.0.name', 'Comunicados')
                ->where('categories.0.slug', 'comunicados')
                ->where('categories.0.description', 'Oficiales')
                ->where('categories.0.articles_count', 2)
                ->hasAll(['categories.0.id', 'categories.0.name', 'categories.0.slug', 'categories.0.description', 'categories.0.articles_count']),
        );
});

it('locks the Articles/Index prop contract: paginated data + category/author names + filters', function (): void {
    $category = Category::factory()->create(['name' => 'Eventos']);
    $author = User::factory()->editor()->create(['name' => 'Reportero Uno']);
    $article = Article::factory()->create([
        'title' => 'Nota destacada',
        'slug' => 'nota-destacada',
        'status' => ArticleStatus::Draft,
        'category_id' => $category->getKey(),
        'author_id' => $author->getKey(),
        'published_at' => null,
    ]);

    actingAs(contentEditor())
        ->get(route('admin.articles.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Index')
                ->has('articles.data', 1)
                ->where('articles.data.0.id', $article->getKey())
                ->where('articles.data.0.title', 'Nota destacada')
                ->where('articles.data.0.slug', 'nota-destacada')
                ->where('articles.data.0.status', 'draft')
                ->where('articles.data.0.status_label_key', 'article_status.draft')
                ->where('articles.data.0.category_name', 'Eventos')
                ->where('articles.data.0.author_name', 'Reportero Uno')
                ->where('articles.data.0.published_at', null)
                ->has('articles.links')
                ->has('articles.meta')
                ->has('categories')
                ->where('categories.0.id', $category->getKey())
                ->where('categories.0.name', 'Eventos')
                ->has('filters'),
        );
});

it('locks the Articles/Create prop contract: category options only', function (): void {
    $category = Category::factory()->create(['name' => 'Deportes']);

    actingAs(contentEditor())
        ->get(route('admin.articles.create'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Create')
                ->has('categories', 1)
                ->where('categories.0.id', $category->getKey())
                ->where('categories.0.name', 'Deportes')
                ->missing('article'),
        );
});

it('locks the Articles/Edit prop contract: full article shape + category options', function (): void {
    $category = Category::factory()->create(['name' => 'Cultura']);
    $article = Article::factory()->create([
        'title' => 'Editable',
        'slug' => 'editable',
        'subtitle' => 'Sub',
        'status' => ArticleStatus::Draft,
        'category_id' => $category->getKey(),
        'meta_title' => 'MT',
        'meta_description' => 'MD',
        'published_at' => null,
    ]);

    actingAs(contentEditor())
        ->get(route('admin.articles.edit', $article))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Edit')
                ->where('article.id', $article->getKey())
                ->where('article.title', 'Editable')
                ->where('article.slug', 'editable')
                ->where('article.subtitle', 'Sub')
                ->where('article.status', 'draft')
                ->where('article.category_id', $category->getKey())
                ->where('article.meta_title', 'MT')
                ->where('article.meta_description', 'MD')
                ->where('article.published_at', null)
                ->has('article.content')
                ->has('article.featured_image_url')
                ->has('categories', 1)
                ->where('categories.0.id', $category->getKey())
                ->where('categories.0.name', 'Cultura'),
        );
});

it('locks the public Articles/Show prop contract: render fields + names, no author_id leaked', function (): void {
    $category = Category::factory()->create(['name' => 'Portada']);
    $author = User::factory()->editor()->create(['name' => 'Firmante']);
    $article = Article::factory()->create([
        'title' => 'Publicado',
        'slug' => 'publicado',
        'subtitle' => 'Resumen',
        'status' => ArticleStatus::Published,
        'category_id' => $category->getKey(),
        'author_id' => $author->getKey(),
        'featured_image_path' => 'articles/2026/06/cover.jpg',
        'published_at' => now(),
    ]);

    get(route('articles.show', $article->slug))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Articles/Show')
                ->where('article.title', 'Publicado')
                ->where('article.subtitle', 'Resumen')
                ->where('article.category_name', 'Portada')
                ->where('article.author_name', 'Firmante')
                ->has('article.content')
                ->has('article.featured_image_url')
                ->has('article.published_at')
                // Internal identifiers must NOT leak onto the public page.
                ->missing('article.author_id')
                ->missing('article.category_id')
                ->missing('article.id'),
        );
});
