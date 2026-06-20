<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;

/*
 * Translation-key regression guard (CONTRACT §12, SPEC §3.1 AUTH-01). The
 * AuthenticateUserAction throws the generic `auth.failed` key (the no-enumeration
 * credential message) and the DashboardController serialises the `role.*`
 * labelKey() targets — all resolved server-side via __(). A missing entry in
 * either lang/{es,en}.json silently resolves to the raw dotted key, which is the
 * exact regression this locks out. No database needed: pure config + lang files.
 */

it('resolves auth.failed to a real translation, not the raw key, in both locales', function (string $locale): void {
    app()->setLocale($locale);

    $message = __('auth.failed');

    expect($message)->not->toBe('auth.failed')
        ->and($message)->not->toBe('');
})->with([
    'spanish' => ['es'],
    'english' => ['en'],
]);

it('resolves every role.* label key for all four roles in both locales', function (string $locale, UserRole $role): void {
    app()->setLocale($locale);

    $key = $role->labelKey();
    $label = __($key);

    expect($key)->toBe("role.{$role->value}")
        ->and($label)->not->toBe($key)
        ->and($label)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (UserRole::cases() as $role) {
            yield "{$locale}: {$role->value}" => [$locale, $role];
        }
    }
});
