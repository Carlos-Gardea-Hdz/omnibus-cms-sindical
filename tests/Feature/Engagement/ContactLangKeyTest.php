<?php

declare(strict_types=1);

/*
 * Translation-key regression guard for the Engagement slice (CONTRACT §10/§12.11, SPEC
 * §3.5). Every flash / thrown key the Engagement Action / controller surfaces via __()
 * must resolve to a real string in BOTH lang/es.json AND lang/en.json — a missing entry
 * silently resolves to the raw dotted key, exactly the regression this locks out. The
 * Engagement slice has NO enum (contact_messages has no status column — Decision B), so
 * there are no enum label keys to check. validation.mexican_phone is REUSED from slice-005
 * and re-asserted here so a future lang-file edit cannot break the contact phone rule. No
 * database needed: pure config + lang files.
 */

/** Every server-side i18n key the Engagement slice flashes / throws / validates with. */
function engagementLangKeys(): array
{
    return [
        // Flash message (the public submission success)
        'contact.submitted',
        // Thrown branch→org consistency error
        'contact.error.branch_org_mismatch',
        // Reused cross-slice rule (the 10-digit phone) — must still resolve for /contact
        'validation.mexican_phone',
    ];
}

it('resolves every Engagement i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (engagementLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('asserts the two NEW Engagement keys are physically present in BOTH lang JSON files', function (string $relativePath): void {
    $path = base_path($relativePath);

    expect(file_exists($path))->toBeTrue("Missing lang file: {$relativePath}");

    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($translations)->toHaveKey('contact.submitted')
        ->and($translations)->toHaveKey('contact.error.branch_org_mismatch');
})->with([
    'es' => ['lang/es.json'],
    'en' => ['lang/en.json'],
]);
