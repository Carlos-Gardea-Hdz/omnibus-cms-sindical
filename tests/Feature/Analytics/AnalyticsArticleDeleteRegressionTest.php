<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * Cross-slice delete regression (CONTRACT §13/§15.15, Decision H — the confirmed branch).
 * page_views.article_id is a SET-NULL FK (§6.4), but Article SOFT-deletes (the model
 * `use SoftDeletes`). The CONFIRMED branch is therefore: the soft delete only stamps
 * deleted_at, so the DB-level SET NULL NEVER fires — the page_views rows survive UNTOUCHED,
 * still pointing at the (now trashed) article, org intact. The admin destroy must return a
 * clean 302 (NEVER a 500) even when the article has analytics events. Because SET NULL is a
 * DB-level edge that does not trip on a soft delete, NO DeleteArticleAction → PageView ref
 * is needed and NO Content arch allow-list change is required (the slice-004 lesson is a
 * no-op here). This test is the guard that pins that conclusion. Runs on PostgreSQL 18.
 */

it('deletes an article that has page_views without a 500 and the events survive (soft delete: SET NULL never fires)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $article = Article::factory()->published()->forBranch($branch)->create();

    PageView::factory()->count(3)->forArticle($article)->create();

    $admin = User::factory()->superAdmin()->create();

    actingAs($admin)
        ->delete(route('admin.articles.destroy', $article))
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    // Soft delete: the article is trashed, not gone.
    expect(Article::withTrashed()->withoutGlobalScope(OrganizationScope::class)
        ->whereKey($article->getKey())->first()?->trashed())->toBeTrue();

    // The page_views survive UNTOUCHED — article_id intact (SET NULL never fired), org intact.
    $survivors = PageView::withoutGlobalScope(OrganizationScope::class)
        ->where('article_id', $article->getKey())->get();

    expect($survivors)->toHaveCount(3);
    $survivors->each(function (PageView $view) use ($article, $organization): void {
        expect($view->article_id)->toBe($article->getKey())
            ->and($view->organization_id)->toBe($organization->getKey());
    });
});
