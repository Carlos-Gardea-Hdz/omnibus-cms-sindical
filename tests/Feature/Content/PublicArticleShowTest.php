<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * BLOCKER 2 — the PUBLIC article path must serve PUBLISHED content ONLY.
 *
 * The public show route resolves an Article by slug WITHOUT the OrganizationScope
 * (public content is org-agnostic by design). Before the fix it carried NO status
 * filter, so any DRAFT or ARCHIVED article — of ANY org — was served 200 to an
 * anonymous visitor by slug. These tests prove the published-only filter closes that:
 * a Draft and an Archived article 404 (anonymous), while a Published one still 200s.
 * They go RED against the pre-fix controller (which served drafts/archived with a 200).
 */

it('404s an anonymous visitor requesting a DRAFT article by slug', function (): void {
    $org = Organization::factory()->create();

    $draft = Article::factory()->forOrganization($org)->draft()->create([
        'slug' => 'secret-draft',
    ]);

    get(route('articles.show', $draft->slug))->assertNotFound();
});

it('404s an anonymous visitor requesting an ARCHIVED article by slug', function (): void {
    $org = Organization::factory()->create();

    $archived = Article::factory()->forOrganization($org)->archived()->create([
        'slug' => 'old-archived',
    ]);

    get(route('articles.show', $archived->slug))->assertNotFound();
});

it('still serves a PUBLISHED article 200 by slug (the happy path is unchanged)', function (): void {
    $org = Organization::factory()->create();

    $published = Article::factory()->forOrganization($org)->published()->create([
        'slug' => 'live-news',
    ]);

    get(route('articles.show', $published->slug))->assertOk();
});
