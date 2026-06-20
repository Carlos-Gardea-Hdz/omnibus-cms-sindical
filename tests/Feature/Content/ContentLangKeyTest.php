<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;

/*
 * Translation-key regression guard (CONTRACT §9/§11.10, SPEC §3.3). Every flash /
 * thrown key the Content slice surfaces server-side via __() must resolve to a real
 * string in BOTH lang/es.json AND lang/en.json. A missing entry silently resolves
 * to the raw dotted key — exactly the regression this locks out. No database
 * needed: pure config + lang files.
 */

/** Every server-side i18n key the Content slice flashes or throws (CONTRACT §9). */
function contentLangKeys(): array
{
    return [
        'articles.created',
        'articles.updated',
        'articles.deleted',
        'articles.published',
        'articles.unpublished',
        'articles.archived',
        'articles.error.publish_requires_image',
        'articles.error.publish_requires_content',
        'articles.error.invalid_transition',
        'articles.error.slug_taken',
        'categories.created',
        'categories.updated',
        'categories.deleted',
        'categories.error.slug_taken',
        'categories.error.in_use',
        'article_status.draft',
        'article_status.published',
        'article_status.archived',
    ];
}

it('resolves every Content i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (contentLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('resolves every ArticleStatus label key in both locales', function (string $locale, ArticleStatus $status): void {
    app()->setLocale($locale);

    $key = $status->labelKey();
    $label = __($key);

    expect($key)->toBe("article_status.{$status->value}")
        ->and($label)->not->toBe($key)
        ->and($label)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (ArticleStatus::cases() as $status) {
            yield "{$locale}: {$status->value}" => [$locale, $status];
        }
    }
});
