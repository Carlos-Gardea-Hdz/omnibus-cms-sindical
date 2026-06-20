<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Article publication state machine, end-to-end (CONTRACT §6/§7/§11.6, SPEC §3.3
 * NEWS-01/NEWS-07, gate decisions B+D). publish/archive sit behind ['auth',
 * 'role:manager','org.scope']. The PublishArticleAction enforces TWO preconditions
 * (both surface as 302 + session errors, NEVER 422 or 500):
 *   - a featured image is required on publish (publish_requires_image);
 *   - the content must have a body (publish_requires_content).
 * The ArticleStatus enum is the authoritative transition guard: an illegal move
 * (e.g. archived → draft) throws InvalidArticleTransitionException, rendered to a
 * 302 + 'status' error by bootstrap/app.php — NEVER a 500. Runs on PostgreSQL 18.
 *
 * SLICE-003 RETROFIT (§17 firewall): the publish/archive group now carries the
 * 'org.scope' middleware, so the acting manager (level 2) is CONFINED to its own
 * organization. The route-model binding of {article} therefore resolves only an
 * article in the manager's org — so the manager and every article fixture below are
 * pinned to ONE shared organization (else the confined-null scope would 404 the
 * binding). This proves the manager publishes only WITHIN its own org; it changes
 * nothing about the state-machine assertions themselves.
 */

/** The organization shared by the acting manager and every article fixture. */
function publishOrg(): Organization
{
    return Organization::factory()->createOne();
}

/** A manager confined to the given org (so org.scope can resolve its articles). */
function manager(?Organization $organization = null): User
{
    return User::factory()->manager()->forOrganization($organization ?? publishOrg())->create();
}

/** A publishable draft pinned to an org: status Draft, a featured image, a body. */
function publishableDraft(Organization $organization): Article
{
    return Article::factory()->forOrganization($organization)->create([
        'status' => ArticleStatus::Draft,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
        'published_at' => null,
    ]);
}

it('refuses to publish a draft with no featured image: 302 + publish_requires_image, stays draft', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Draft,
        'featured_image_path' => null,
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.publish', $article))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors(['status' => __('articles.error.publish_requires_image')]);

    expect($article->fresh())
        ->status->toBe(ArticleStatus::Draft)
        ->published_at->toBeNull();
});

it('refuses to publish a draft with empty content: 302 + publish_requires_content, stays draft', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Draft,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
        'content' => ['type' => 'doc', 'content' => []], // no body nodes
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.publish', $article))
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors(['status' => __('articles.error.publish_requires_content')]);

    expect($article->fresh()->status)->toBe(ArticleStatus::Draft);
});

it('publishes a valid draft: status flips to Published, published_at is stamped near now', function (): void {
    $org = publishOrg();
    $article = publishableDraft($org);

    actingAs(manager($org))
        ->post(route('admin.articles.publish', $article))
        ->assertRedirect()
        ->assertSessionHas('success', __('articles.published'))
        ->assertSessionHasNoErrors();

    $fresh = $article->fresh();

    // Enum-cast attribute compared as an ENUM INSTANCE.
    expect($fresh->status)->toBe(ArticleStatus::Published)
        ->and($fresh->published_at)->not->toBeNull()
        ->and($fresh->published_at->diffInSeconds(now()))->toBeLessThan(10);
});

it('unpublishes a published article back to draft (published → draft), clearing published_at', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Published,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
        'published_at' => now()->subDay(),
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.archive', $article), ['status' => ArticleStatus::Draft->value])
        ->assertRedirect();

    // Whatever the wiring (dedicated unpublish vs. transition target), the enum guard
    // permits published → draft, so the article must end as a draft with no timestamp.
    // (This is the exact contract the Index page's "unpublish" button posts
    // {status:'draft'} against — an empty body would archive instead.)
    expect($article->fresh()->status)->toBe(ArticleStatus::Draft)
        ->and($article->fresh()->published_at)->toBeNull();
})->skip(fn (): bool => ! Route::has('admin.articles.archive'), 'transition route not registered');

it('archives a published article (published → archived)', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Published,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
        'published_at' => now()->subDay(),
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.archive', $article))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
});

it('republishes an archived article (archived → published)', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Archived,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.publish', $article))
        ->assertRedirect()
        ->assertSessionHas('success', __('articles.published'))
        ->assertSessionHasNoErrors();

    expect($article->fresh()->status)->toBe(ArticleStatus::Published);
});

it('rejects an illegal archived → draft transition with a 302 + invalid_transition error, NEVER a 500', function (): void {
    $org = publishOrg();
    $article = Article::factory()->forOrganization($org)->create([
        'status' => ArticleStatus::Archived,
        'featured_image_path' => 'articles/2026/06/cover.jpg',
    ]);

    actingAs(manager($org))
        ->post(route('admin.articles.archive', $article), ['status' => ArticleStatus::Draft->value])
        ->assertRedirect()
        ->assertStatus(302)
        ->assertSessionHasErrors('status');

    // The enum guard rejected the move: the row is untouched, no 500.
    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
})->skip(fn (): bool => ! Route::has('admin.articles.archive'), 'transition route not registered');
