<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\RepresentativeShift;

/*
 * Translation-key regression guard for the Organization slice (CONTRACT §14/§16,
 * SPEC §3.2). Every flash / thrown key any Org Action/exception/controller surfaces
 * via __() must resolve to a real string in BOTH lang/es.json AND lang/en.json — a
 * missing entry silently resolves to the raw dotted key, exactly the regression this
 * locks out. The representative_shift.* enum labels are checked through the enum's
 * own labelKey() so the enum and the lang files can never drift apart. No database
 * needed: pure config + lang files.
 */

/** Every server-side i18n key the Organization slice flashes or throws (CONTRACT §14). */
function organizationLangKeys(): array
{
    return [
        // Organizations
        'organizations.created',
        'organizations.updated',
        'organizations.deleted',
        'organizations.error.slug_taken',
        'organizations.error.has_branches',
        // Branches
        'branches.created',
        'branches.updated',
        'branches.deleted',
        'branches.error.has_representatives',
        // Directors
        'directors.created',
        'directors.updated',
        'directors.deleted',
        'directors.error.already_assigned',
        // Representatives
        'representatives.created',
        'representatives.updated',
        'representatives.deleted',
        // Municipalities
        'municipalities.created',
        'municipalities.updated',
        'municipalities.deleted',
        'municipalities.error.in_use',
        // Shift labels
        'representative_shift.morning',
        'representative_shift.evening',
        'representative_shift.night',
    ];
}

it('resolves every Organization i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (organizationLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('resolves every RepresentativeShift label key through the enum in both locales', function (string $locale, RepresentativeShift $shift): void {
    app()->setLocale($locale);

    $key = $shift->labelKey();
    $label = __($key);

    expect($key)->toBe("representative_shift.{$shift->value}")
        ->and($label)->not->toBe($key)
        ->and($label)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (RepresentativeShift::cases() as $shift) {
            yield "{$locale}: {$shift->value}" => [$locale, $shift];
        }
    }
});
