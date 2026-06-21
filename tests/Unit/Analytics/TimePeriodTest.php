<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\TimePeriod;

/*
 * Pure-logic coverage for the TimePeriod backed enum (CONTRACT §2/§15.16, SPEC §3.7).
 * No framework bootstrap, no database — the enum is the SSOT for the analytics time
 * bucketing. truncUnit() returns the Postgres date_trunc unit (NO magic string in the
 * grouped aggregate queries) and MUST equal the case value (day|week|month). default()
 * is Month (the dashboard renders the monthly view when no period filter is supplied).
 * labelKey() namespaces the i18n key under analytics.period.*. Every other behaviour
 * (the actual grouped query) is proven through the aggregate feature tests, not here.
 */

it('backs exactly the three time periods with their lowercase values', function (): void {
    expect(TimePeriod::cases())->toHaveCount(3);

    $values = array_map(fn (TimePeriod $period): string => $period->value, TimePeriod::cases());

    expect($values)->toBe(['day', 'week', 'month']);
});

it('returns the Postgres date_trunc unit equal to the case value (no magic string)', function (TimePeriod $period, string $unit): void {
    expect($period->truncUnit())->toBe($unit)
        ->and($period->truncUnit())->toBe($period->value);
})->with([
    'day' => [TimePeriod::Day, 'day'],
    'week' => [TimePeriod::Week, 'week'],
    'month' => [TimePeriod::Month, 'month'],
]);

it('namespaces the i18n label key under analytics.period.*', function (TimePeriod $period, string $key): void {
    expect($period->labelKey())->toBe($key);
})->with([
    'day' => [TimePeriod::Day, 'analytics.period.day'],
    'week' => [TimePeriod::Week, 'analytics.period.week'],
    'month' => [TimePeriod::Month, 'analytics.period.month'],
]);

it('defaults to Month (the dashboard renders the monthly view unfiltered)', function (): void {
    expect(TimePeriod::default())->toBe(TimePeriod::Month);
});
