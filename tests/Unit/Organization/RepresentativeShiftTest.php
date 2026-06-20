<?php

declare(strict_types=1);

use App\Domain\Organization\Enums\RepresentativeShift;

/*
 * RepresentativeShift backed enum (CONTRACT §5, SPEC §3.2 ORG-03). Pure unit test,
 * no database: the three canonical shifts (morning/evening/night) carry stable
 * string backing values, a deterministic labelKey() in the representative_shift.*
 * namespace (resolved by __() and the React layer), and a non-empty magenta-palette
 * color() (SPEC §1.4). No magic strings: every shift flows through the enum.
 */

it('exposes exactly the three canonical shifts with stable backing values', function (): void {
    expect(RepresentativeShift::cases())->toHaveCount(3)
        ->and(RepresentativeShift::Morning->value)->toBe('morning')
        ->and(RepresentativeShift::Evening->value)->toBe('evening')
        ->and(RepresentativeShift::Night->value)->toBe('night');
});

it('is a string-backed enum so it casts cleanly on the model', function (): void {
    expect(RepresentativeShift::from('morning'))->toBe(RepresentativeShift::Morning)
        ->and(RepresentativeShift::tryFrom('nope'))->toBeNull();
});

it('derives the labelKey from the namespaced backing value', function (RepresentativeShift $shift): void {
    expect($shift->labelKey())->toBe("representative_shift.{$shift->value}");
})->with([
    'morning' => [RepresentativeShift::Morning],
    'evening' => [RepresentativeShift::Evening],
    'night' => [RepresentativeShift::Night],
]);

it('returns a non-empty hex color for every case (magenta palette, SPEC §1.4)', function (RepresentativeShift $shift): void {
    expect($shift->color())
        ->toBeString()
        ->not->toBe('')
        ->toStartWith('#');
})->with([
    'morning' => [RepresentativeShift::Morning],
    'evening' => [RepresentativeShift::Evening],
    'night' => [RepresentativeShift::Night],
]);
