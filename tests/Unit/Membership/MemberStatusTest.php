<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberStatus;

/*
 * Pure-logic coverage for the MemberStatus backed enum (CONTRACT §2/§14, SPEC §3.6).
 * No framework bootstrap, no database — the enum is the SSOT for the member approval
 * lifecycle. The transition graph (Decision C — both review outcomes are terminal):
 *
 *   pending  → approved, rejected
 *   approved → []            (terminal)
 *   rejected → []            (terminal)
 *
 * Every other edge — including every self-loop and both terminal → * edges — is
 * illegal. canTransitionTo() is the load-bearing guard the Approve/Reject Actions
 * lean on, so the full matrix is pinned explicitly here; never magic strings. The
 * default on registration is Pending — proven through the feature tests, not here.
 */

it('backs exactly the three lifecycle statuses with their lowercase values', function (): void {
    expect(MemberStatus::cases())->toHaveCount(3);

    $values = array_map(fn (MemberStatus $status): string => $status->value, MemberStatus::cases());

    expect($values)->toBe(['pending', 'approved', 'rejected']);
});

it('exposes the exact allowed-transition set per state (Decision C graph)', function (MemberStatus $from, array $expected): void {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'pending → [approved, rejected]' => [
        MemberStatus::Pending,
        [MemberStatus::Approved, MemberStatus::Rejected],
    ],
    'approved → [] (terminal)' => [
        MemberStatus::Approved,
        [],
    ],
    'rejected → [] (terminal)' => [
        MemberStatus::Rejected,
        [],
    ],
]);

it('permits exactly the two legal transitions out of pending', function (MemberStatus $to): void {
    expect(MemberStatus::Pending->canTransitionTo($to))->toBeTrue();
})->with([
    'pending → approved' => [MemberStatus::Approved],
    'pending → rejected' => [MemberStatus::Rejected],
]);

it('rejects every illegal transition: both terminal states are sinks, no self-loop, no reopen', function (MemberStatus $from, MemberStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // approved is terminal: every outgoing edge is illegal
    'approved → pending' => [MemberStatus::Approved, MemberStatus::Pending],
    'approved → rejected' => [MemberStatus::Approved, MemberStatus::Rejected],
    // rejected is terminal: every outgoing edge is illegal
    'rejected → pending' => [MemberStatus::Rejected, MemberStatus::Pending],
    'rejected → approved' => [MemberStatus::Rejected, MemberStatus::Approved],
    // self-loops are never legal — pending → pending is the one the lifecycle test re-asserts
    'pending → pending (self)' => [MemberStatus::Pending, MemberStatus::Pending],
    'approved → approved (self)' => [MemberStatus::Approved, MemberStatus::Approved],
    'rejected → rejected (self)' => [MemberStatus::Rejected, MemberStatus::Rejected],
]);

it('agrees canTransitionTo with allowedTransitions for the entire 3×3 matrix', function (MemberStatus $from, MemberStatus $to): void {
    expect($from->canTransitionTo($to))
        ->toBe(in_array($to, $from->allowedTransitions(), strict: true));
})->with(function (): iterable {
    foreach (MemberStatus::cases() as $from) {
        foreach (MemberStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});

it('derives a namespaced i18n label key per status: member_status.{value}', function (MemberStatus $status): void {
    expect($status->labelKey())->toBe("member_status.{$status->value}")
        ->and($status->labelKey())->not->toBe('');
})->with([
    'pending' => [MemberStatus::Pending],
    'approved' => [MemberStatus::Approved],
    'rejected' => [MemberStatus::Rejected],
]);

it('maps each status to its non-empty magenta-palette hex colour token', function (MemberStatus $status, string $hex): void {
    expect($status->color())
        ->toBeString()
        ->not->toBe('')
        ->toStartWith('#')
        ->toBe($hex);
})->with([
    'pending' => [MemberStatus::Pending, '#A03CC7'],
    'approved' => [MemberStatus::Approved, '#DD00FF'],
    'rejected' => [MemberStatus::Rejected, '#9211CF'],
]);
