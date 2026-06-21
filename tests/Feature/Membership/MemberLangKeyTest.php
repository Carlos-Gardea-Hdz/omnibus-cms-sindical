<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;

/*
 * Translation-key regression guard for the Membership slice (CONTRACT §12/§14, SPEC
 * §3.6). Every flash / thrown / validation key any Membership Action / exception /
 * controller / PII rule surfaces via __() must resolve to a real string in BOTH
 * lang/es.json AND lang/en.json — a missing entry silently resolves to the raw dotted
 * key, exactly the regression this locks out. The member_status.* enum labels are
 * checked through the enum's own labelKey() so the enum and the lang files can never
 * drift apart. No database needed: pure config + lang files.
 */

/** Every server-side i18n key the Membership slice flashes / throws / validates with. */
function membershipLangKeys(): array
{
    return [
        // Flash messages
        'membership.registered',
        'members.approved',
        'members.rejected',
        // Thrown transition error
        'members.error.invalid_transition',
        // PII validation-rule messages
        'validation.curp_format',
        'validation.rfc_format',
        'validation.mexican_phone',
        // Reused cross-slice key (DeleteMunicipalityAction member referrer, Decision I)
        'municipalities.error.in_use',
        // Status labels
        'member_status.pending',
        'member_status.approved',
        'member_status.rejected',
    ];
}

it('resolves every Membership i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (membershipLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('resolves every MemberStatus label key through the enum in both locales', function (string $locale, MemberStatus $status): void {
    app()->setLocale($locale);

    $key = $status->labelKey();
    $label = __($key);

    expect($key)->toBe("member_status.{$status->value}")
        ->and($label)->not->toBe($key)
        ->and($label)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (MemberStatus::cases() as $status) {
            yield "{$locale}: {$status->value}" => [$locale, $status];
        }
    }
});
