<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;

/*
 * Translation-key regression guard for the Jobs slice (CONTRACT §11/§13, SPEC §3.4).
 * Every flash / thrown key any Jobs Action/exception/controller surfaces via __()
 * must resolve to a real string in BOTH lang/es.json AND lang/en.json — a missing
 * entry silently resolves to the raw dotted key, exactly the regression this locks
 * out. The job_status.* enum labels are checked through the enum's own labelKey() so
 * the enum and the lang files can never drift apart. No database needed: pure config
 * + lang files.
 */

/** Every server-side i18n key the Jobs slice flashes or throws (CONTRACT §11). */
function jobsLangKeys(): array
{
    return [
        // Flash messages
        'jobs.created',
        'jobs.updated',
        'jobs.deleted',
        'jobs.status_changed',
        // Thrown / validation errors
        'jobs.error.invalid_transition',
        'jobs.error.branch_org_mismatch',
        'jobs.error.salary_range',
        // Status labels
        'job_status.draft',
        'job_status.active',
        'job_status.paused',
        'job_status.closed',
    ];
}

it('resolves every Jobs i18n key to a real translation, not the raw key, in both locales', function (string $locale, string $key): void {
    app()->setLocale($locale);

    $message = __($key);

    expect($message)->not->toBe($key)
        ->and($message)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (jobsLangKeys() as $key) {
            yield "{$locale}: {$key}" => [$locale, $key];
        }
    }
});

it('resolves every JobStatus label key through the enum in both locales', function (string $locale, JobStatus $status): void {
    app()->setLocale($locale);

    $key = $status->labelKey();
    $label = __($key);

    expect($key)->toBe("job_status.{$status->value}")
        ->and($label)->not->toBe($key)
        ->and($label)->not->toBe('');
})->with(function (): iterable {
    foreach (['es', 'en'] as $locale) {
        foreach (JobStatus::cases() as $status) {
            yield "{$locale}: {$status->value}" => [$locale, $status];
        }
    }
});
