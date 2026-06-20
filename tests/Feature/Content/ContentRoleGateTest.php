<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * RBAC gating for the Content admin routes (CONTRACT §7/§11.8, SPEC §3.3 §10.2,
 * gate decision D — the #1 access-control control of the slice). The split:
 *   - create/edit/CRUD (articles + categories) sit behind ['auth','role:editor'];
 *   - publish/archive sit behind ['auth','role:manager'] — an editor may author
 *     but NOT publish.
 * Therefore: a guest is bounced to /login (302, never 403) on EVERY admin route;
 * an editor reaches the CRUD controllers but is FORBIDDEN (403) on publish/archive;
 * a manager (and above) passes the publish gate. PostgreSQL 18 via RefreshDatabase.
 */

/** The editor-gated CRUD routes as [method, routeName, needsModel]. */
function contentEditorRoutes(): array
{
    return [
        'articles.index' => ['get', 'admin.articles.index', false],
        'articles.create' => ['get', 'admin.articles.create', false],
        'articles.store' => ['post', 'admin.articles.store', false],
        'articles.edit' => ['get', 'admin.articles.edit', true],
        'articles.update' => ['put', 'admin.articles.update', true],
        'articles.destroy' => ['delete', 'admin.articles.destroy', true],
        'categories.index' => ['get', 'admin.categories.index', false],
        'categories.store' => ['post', 'admin.categories.store', false],
        'categories.update' => ['put', 'admin.categories.update', true],
        'categories.destroy' => ['delete', 'admin.categories.destroy', true],
    ];
}

/** The manager-gated transition routes (publish / archive). */
function contentManagerRoutes(): array
{
    return [
        'articles.publish' => ['post', 'admin.articles.publish'],
        'articles.archive' => ['post', 'admin.articles.archive'],
    ];
}

/** Resolve the URL, binding a freshly created model of the right type when needed. */
function contentUrl(string $name, bool $needsModel): string
{
    if (! $needsModel) {
        return route($name);
    }

    $model = str_contains($name, 'categories')
        ? Category::factory()->create()
        : Article::factory()->create();

    return route($name, $model);
}

it('redirects a guest to login (302, never 403) on every editor-gated content route', function (string $method, string $name, bool $needsModel): void {
    $this->{$method}(contentUrl($name, $needsModel))
        ->assertRedirect(route('login'));
})->with(contentEditorRoutes());

it('redirects a guest to login on every manager-gated transition route', function (string $method, string $name): void {
    $article = Article::factory()->create();

    $this->{$method}(route($name, $article))
        ->assertRedirect(route('login'));
})->with(contentManagerRoutes());

it('lets an editor reach every editor-gated CRUD controller (never 403, never login)', function (string $method, string $name, bool $needsModel): void {
    $response = actingAs(User::factory()->editor()->create())
        ->{$method}(contentUrl($name, $needsModel));

    // The gate passes: the controller runs. A bare mutation payload yields a 302
    // with validation errors (web convention) or a 200 GET — never a 403 / login.
    expect($response->getStatusCode())->not->toBe(403)
        ->and($response->headers->get('Location'))->not->toBe(route('login'));
})->with(contentEditorRoutes());

it('forbids an editor on the manager-gated publish route with a 403', function (): void {
    $article = Article::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.publish', $article))
        ->assertForbidden();
});

it('forbids an editor on the manager-gated archive route with a 403', function (): void {
    $article = Article::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.archive', $article))
        ->assertForbidden();
});

it('lets a manager reach the publish/archive controllers (never 403, never login)', function (string $method, string $name): void {
    $article = Article::factory()->create();

    $response = actingAs(User::factory()->manager()->create())
        ->{$method}(route($name, $article));

    expect($response->getStatusCode())->not->toBe(403)
        ->and($response->headers->get('Location'))->not->toBe(route('login'));
})->with(contentManagerRoutes());

it('lets a manager through the lower editor CRUD gate (higher role satisfies a lower gate)', function (): void {
    actingAs(User::factory()->manager()->create())
        ->get(route('admin.articles.index'))
        ->assertOk();
});

it('lets a super_admin publish (top of the ladder clears every content gate)', function (): void {
    $article = Article::factory()->create();

    $response = actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.articles.publish', $article));

    expect($response->getStatusCode())->not->toBe(403);
});
