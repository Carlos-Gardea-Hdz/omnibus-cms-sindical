<?php

declare(strict_types=1);

/*
 * Translation-key regression guard for Identity Phase 2 (CONTRACT §E). Two distinct
 * surfaces:
 *   - SERVER keys resolved via __() (the demo flash messages + the user-CRUD
 *     success/error messages) MUST resolve to a real string in BOTH lang/{es,en}.json.
 *   - FRONTEND keys consumed by the React t() MUST be physically present in BOTH
 *     resources/locales/{es,en}.json.
 * A missing entry silently resolves to the raw dotted key — exactly the regression this
 * locks out. Pure config + JSON files (no DB needed).
 */

/** Server-side keys resolved via __() (demo flashes + user-CRUD messages). */
function identityPhase2ServerKeys(): array
{
    return [
        'demo.throttled',
        'demo.expired',
        'demo.blocked',
        'users.created',
        'users.updated',
        'users.deleted',
        'users.error.cannot_delete_self',
        'users.error.cannot_delete_last_super_admin',
        'users.error.cannot_assign_super_admin',
        'users.error.super_admin_self_only',
    ];
}

/** Frontend keys consumed by the React t() (demo banner/CTA/presets + user-admin UI). */
function identityPhase2FrontendKeys(): array
{
    return [
        'demo.banner.active',
        'demo.banner.expires_in',
        'demo.cta.try',
        'demo.preset.administrator.title',
        'demo.preset.administrator.desc',
        'demo.preset.manager.title',
        'demo.preset.manager.desc',
        'demo.preset.editor.title',
        'demo.preset.editor.desc',
        'admin.users.title',
        'admin.users.new',
        'admin.users.edit_title',
        'admin.users.field.username',
        'admin.users.field.name',
        'admin.users.field.email',
        'admin.users.field.password',
        'admin.users.field.password_confirmation',
        'admin.users.field.role',
        'admin.users.field.organization',
        'admin.users.submit',
        'admin.users.delete',
        'admin.users.confirm_delete',
        'admin.users.badge.demo',
    ];
}

it('resolves every Identity Phase 2 server key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (identityPhase2ServerKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('physically contains every server key in BOTH lang JSON files', function (string $relativePath): void {
    $path = base_path($relativePath);
    expect(file_exists($path))->toBeTrue("Missing lang file: {$relativePath}");

    /** @var array<string, string> $translations */
    $translations = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach (identityPhase2ServerKeys() as $key) {
        expect($translations)->toHaveKey($key, message: "Missing server key {$key} in {$relativePath}");
    }
})->with([
    'es' => ['lang/es.json'],
    'en' => ['lang/en.json'],
]);

it('physically contains every frontend key in BOTH resources/locales JSON files', function (string $relativePath): void {
    $path = base_path($relativePath);
    expect(file_exists($path))->toBeTrue("Missing locale file: {$relativePath}");

    /** @var array<string, string> $translations — the frontend locale files use FLAT dotted keys. */
    $translations = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach (identityPhase2FrontendKeys() as $key) {
        expect($translations)->toHaveKey($key, message: "Missing frontend key {$key} in {$relativePath}");
    }
})->with([
    'es' => ['resources/locales/es.json'],
    'en' => ['resources/locales/en.json'],
]);
