<?php

declare(strict_types=1);

/*
 * Translation-key regression guard for the Analytics slice (CONTRACT §11/§15.13, SPEC
 * §3.7). The TimePeriod enum's labelKey() resolves to analytics.period.{day,week,month};
 * these are the only NEW server-side i18n keys the Analytics slice surfaces (the dashboard
 * UI copy — titles/axis labels — lives in resources/locales/* for the frontend t(), NOT
 * here). Each MUST resolve to a real string in BOTH lang/es.json AND lang/en.json — a
 * missing entry silently resolves to the raw dotted key, exactly the regression this locks
 * out. No database needed: pure config + lang files.
 */

/** Every NEW server-side i18n key the Analytics slice surfaces via the TimePeriod enum. */
function analyticsLangKeys(): array
{
    return [
        'analytics.period.day',
        'analytics.period.week',
        'analytics.period.month',
    ];
}

it('resolves every Analytics i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (analyticsLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('asserts the three Analytics period keys are physically present in BOTH lang JSON files', function (string $relativePath): void {
    $path = base_path($relativePath);

    expect(file_exists($path))->toBeTrue("Missing lang file: {$relativePath}");

    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($translations)->toHaveKey('analytics.period.day')
        ->and($translations)->toHaveKey('analytics.period.week')
        ->and($translations)->toHaveKey('analytics.period.month');
})->with([
    'es' => ['lang/es.json'],
    'en' => ['lang/en.json'],
]);
