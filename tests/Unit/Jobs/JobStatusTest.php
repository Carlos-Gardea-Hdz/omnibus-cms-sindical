<?php

declare(strict_types=1);

use App\Domain\Jobs\Enums\JobStatus;

/*
 * Pure-logic coverage for the JobStatus backed enum (CONTRACT §2/§13, SPEC §3.4).
 * No framework bootstrap, no database — the enum is the SSOT for the job-posting
 * lifecycle. The transition graph (Decision B — exact graph):
 *
 *   draft   → active, closed
 *   active  → paused, closed
 *   paused  → active, closed
 *   closed  → []            (terminal)
 *
 * Every other edge — including every self-loop — is illegal. canTransitionTo() is
 * the load-bearing guard ToggleJobStatusAction leans on, so the full matrix is
 * pinned explicitly here; never magic strings. The default on create is Active
 * (JOB-01) — proven through the Action/CRUD feature tests, not here.
 */

it('backs exactly the four lifecycle statuses with their lowercase values', function (): void {
    expect(JobStatus::cases())->toHaveCount(4);

    $values = array_map(fn (JobStatus $status): string => $status->value, JobStatus::cases());

    expect($values)->toBe(['draft', 'active', 'paused', 'closed']);
});

it('exposes the exact allowed-transition set per state (Decision B graph)', function (JobStatus $from, array $expected): void {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'draft → [active, closed]' => [
        JobStatus::Draft,
        [JobStatus::Active, JobStatus::Closed],
    ],
    'active → [paused, closed]' => [
        JobStatus::Active,
        [JobStatus::Paused, JobStatus::Closed],
    ],
    'paused → [active, closed]' => [
        JobStatus::Paused,
        [JobStatus::Active, JobStatus::Closed],
    ],
    'closed → [] (terminal)' => [
        JobStatus::Closed,
        [],
    ],
]);

it('permits every one of the six legal transitions', function (JobStatus $from, JobStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'draft → active' => [JobStatus::Draft, JobStatus::Active],
    'draft → closed' => [JobStatus::Draft, JobStatus::Closed],
    'active → paused' => [JobStatus::Active, JobStatus::Paused],
    'active → closed' => [JobStatus::Active, JobStatus::Closed],
    'paused → active' => [JobStatus::Paused, JobStatus::Active],
    'paused → closed' => [JobStatus::Paused, JobStatus::Closed],
]);

it('rejects every illegal transition, including every self-loop and all closed → * edges', function (JobStatus $from, JobStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // closed is terminal: every outgoing edge is illegal
    'closed → draft' => [JobStatus::Closed, JobStatus::Draft],
    'closed → active' => [JobStatus::Closed, JobStatus::Active],
    'closed → paused' => [JobStatus::Closed, JobStatus::Paused],
    // backwards / skipping edges
    'active → draft (cannot reopen to draft)' => [JobStatus::Active, JobStatus::Draft],
    'paused → draft (cannot reopen to draft)' => [JobStatus::Paused, JobStatus::Draft],
    'draft → paused (must activate first)' => [JobStatus::Draft, JobStatus::Paused],
    // self-loops are never legal
    'draft → draft (self)' => [JobStatus::Draft, JobStatus::Draft],
    'active → active (self)' => [JobStatus::Active, JobStatus::Active],
    'paused → paused (self)' => [JobStatus::Paused, JobStatus::Paused],
    'closed → closed (self)' => [JobStatus::Closed, JobStatus::Closed],
]);

it('agrees canTransitionTo with allowedTransitions for the entire 4×4 matrix', function (JobStatus $from, JobStatus $to): void {
    expect($from->canTransitionTo($to))
        ->toBe(in_array($to, $from->allowedTransitions(), strict: true));
})->with(function (): iterable {
    foreach (JobStatus::cases() as $from) {
        foreach (JobStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});

it('derives a namespaced i18n label key per status: job_status.{value}', function (JobStatus $status): void {
    expect($status->labelKey())->toBe("job_status.{$status->value}")
        ->and($status->labelKey())->not->toBe('');
})->with([
    'draft' => [JobStatus::Draft],
    'active' => [JobStatus::Active],
    'paused' => [JobStatus::Paused],
    'closed' => [JobStatus::Closed],
]);

it('maps each status to a non-empty magenta-palette hex colour token', function (JobStatus $status, string $hex): void {
    expect($status->color())
        ->toBeString()
        ->not->toBe('')
        ->toStartWith('#')
        ->toBe($hex);
})->with([
    'draft' => [JobStatus::Draft, '#A03CC7'],
    'active' => [JobStatus::Active, '#DD00FF'],
    'paused' => [JobStatus::Paused, '#E647FF'],
    'closed' => [JobStatus::Closed, '#9211CF'],
]);
