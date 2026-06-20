<?php

declare(strict_types=1);

use App\Domain\Content\Enums\ArticleStatus;

/*
 * Pure-logic coverage for the ArticleStatus backed enum (CONTRACT §2/§11.1, SPEC
 * §3.3 NEWS-07). No framework bootstrap, no database — the enum is the SSOT for the
 * publication state machine. The transition graph is the superset of NEWS-07
 * (Deviation B — unpublish published→draft is legal):
 *
 *   draft      → published, archived
 *   published  → archived, draft   (the published→draft edge is the unpublish)
 *   archived   → published         (republish)
 *
 * Every other edge — including every self-loop — is illegal. canTransitionTo() is
 * the load-bearing guard every Article transition Action leans on, so the full
 * matrix is pinned explicitly here; never magic strings.
 */

it('backs exactly the three SPEC §3.3 statuses with their lowercase values', function (): void {
    expect(ArticleStatus::cases())->toHaveCount(3);

    $values = array_map(fn (ArticleStatus $status): string => $status->value, ArticleStatus::cases());

    expect($values)->toBe(['draft', 'published', 'archived']);
});

it('exposes the exact allowed-transition set per state (Deviation B superset of NEWS-07)', function (ArticleStatus $from, array $expected): void {
    expect($from->allowedTransitions())->toBe($expected);
})->with([
    'draft → [published, archived]' => [
        ArticleStatus::Draft,
        [ArticleStatus::Published, ArticleStatus::Archived],
    ],
    'published → [archived, draft]' => [
        ArticleStatus::Published,
        [ArticleStatus::Archived, ArticleStatus::Draft],
    ],
    'archived → [published]' => [
        ArticleStatus::Archived,
        [ArticleStatus::Published],
    ],
]);

it('permits every one of the five legal transitions', function (ArticleStatus $from, ArticleStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'draft → published (publish)' => [ArticleStatus::Draft, ArticleStatus::Published],
    'draft → archived' => [ArticleStatus::Draft, ArticleStatus::Archived],
    'published → archived' => [ArticleStatus::Published, ArticleStatus::Archived],
    'published → draft (unpublish)' => [ArticleStatus::Published, ArticleStatus::Draft],
    'archived → published (republish)' => [ArticleStatus::Archived, ArticleStatus::Published],
]);

it('rejects every illegal transition, including every self-loop', function (ArticleStatus $from, ArticleStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // skips / illegal edges
    'archived → draft (cannot un-archive to draft directly)' => [ArticleStatus::Archived, ArticleStatus::Draft],
    // self-loops are never legal
    'draft → draft (self)' => [ArticleStatus::Draft, ArticleStatus::Draft],
    'published → published (self)' => [ArticleStatus::Published, ArticleStatus::Published],
    'archived → archived (self)' => [ArticleStatus::Archived, ArticleStatus::Archived],
]);

it('agrees canTransitionTo with allowedTransitions for the entire 3×3 matrix', function (ArticleStatus $from, ArticleStatus $to): void {
    expect($from->canTransitionTo($to))
        ->toBe(in_array($to, $from->allowedTransitions(), strict: true));
})->with(function (): iterable {
    foreach (ArticleStatus::cases() as $from) {
        foreach (ArticleStatus::cases() as $to) {
            yield "{$from->value} → {$to->value}" => [$from, $to];
        }
    }
});

it('derives a namespaced i18n label key per status: article_status.{value}', function (ArticleStatus $status): void {
    expect($status->labelKey())->toBe("article_status.{$status->value}")
        ->and($status->labelKey())->not->toBe('');
})->with([
    'draft' => [ArticleStatus::Draft],
    'published' => [ArticleStatus::Published],
    'archived' => [ArticleStatus::Archived],
]);

it('maps each status to a non-empty colour token', function (ArticleStatus $status): void {
    expect($status->color())->toBeString()->not->toBe('');
})->with([
    'draft' => [ArticleStatus::Draft],
    'published' => [ArticleStatus::Published],
    'archived' => [ArticleStatus::Archived],
]);
