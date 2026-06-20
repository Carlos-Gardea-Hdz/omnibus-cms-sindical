<?php

declare(strict_types=1);

use App\Domain\Content\Services\SanitizesContent;

/*
 * Pure-logic coverage for the SanitizesContent service (CONTRACT §5/§10/§11.2,
 * SPEC §3.3 — the load-bearing stored-XSS defense). No framework bootstrap, no
 * database: clean() takes a decoded TipTap JSONB tree and returns a sanitized copy
 * by ALLOW-LIST (SPEC §3.3):
 *   - tags: p, strong, em, u, a, ul, ol, li, span, div, br  (+ doc/text wrappers)
 *   - attrs: class, href, target  (every other attr — every on* handler, every
 *     style — is stripped)
 *   - href schemes javascript:/data:/vbscript: are dropped
 *   - script/raw-HTML node types are removed entirely
 * The cleaned tree is always a structurally-valid {type:'doc',content:[…]}.
 *
 * These assertions probe the SEMANTIC tree (the persisted shape), and additionally
 * the JSON-encoded blob so a dangerous token can never survive in any string form.
 */

function sanitizer(): SanitizesContent
{
    return new SanitizesContent;
}

/**
 * Flatten the cleaned tree to its JSON string so a token search covers every nested
 * value. Slashes are left unescaped (JSON_UNESCAPED_SLASHES) so a preserved URL like
 * `https://ok.test` reads verbatim — json_encode's default `\/` escaping would
 * otherwise make a legitimate href assertion unmatchable. This does NOT relax any
 * security check: every dropped-token assertion (`script`, `javascript:`, `onerror`,
 * `style`, the data:/vbscript: schemes) is slash-independent.
 */
function asJson(array $doc): string
{
    return json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('always returns a structurally valid doc node even when everything is stripped', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
        ],
    ]);

    expect($clean['type'])->toBe('doc')
        ->and($clean)->toHaveKey('content')
        ->and($clean['content'])->toBeArray();

    // The script node is gone; no dangerous remnant remains anywhere in the tree.
    expect(asJson($clean))->not->toContain('script');
});

it('drops a script node type entirely', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'safe']]],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(document.cookie)']]],
        ],
    ]);

    $json = asJson($clean);

    expect($json)->not->toContain('script')
        ->and($json)->not->toContain('alert(document.cookie)')
        ->and($json)->toContain('safe'); // the legitimate sibling survived
});

it('strips every on* event-handler attribute', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['onclick' => 'steal()', 'onerror' => 'boom()', 'class' => 'lead'],
            'content' => [['type' => 'text', 'text' => 'hello']],
        ]],
    ]);

    $json = asJson($clean);

    expect($json)->not->toContain('onclick')
        ->and($json)->not->toContain('onerror')
        ->and($json)->not->toContain('steal()')
        ->and($json)->not->toContain('boom()')
        // the allow-listed class attribute survives
        ->and($json)->toContain('lead');
});

it('strips every style attribute', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['style' => 'position:fixed;background:url(javascript:alert(1))'],
            'content' => [['type' => 'text', 'text' => 'x']],
        ]],
    ]);

    expect(asJson($clean))->not->toContain('style')
        ->and(asJson($clean))->not->toContain('position:fixed');
});

it('drops a javascript: href on a link mark but keeps an http(s) one', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'evil',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                ],
                [
                    'type' => 'text',
                    'text' => 'good',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test', 'target' => '_blank']]],
                ],
            ],
        ]],
    ]);

    $json = asJson($clean);

    expect($json)->not->toContain('javascript:alert(1)')
        ->and($json)->not->toContain('javascript:')
        // the legitimate link and its text both survive
        ->and($json)->toContain('https://example.test')
        ->and($json)->toContain('good');
});

it('drops data: and vbscript: href schemes', function (string $scheme): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'link',
                'marks' => [['type' => 'link', 'attrs' => ['href' => $scheme]]],
            ]],
        ]],
    ]);

    expect(asJson($clean))->not->toContain($scheme);
})->with([
    'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
    'vbscript' => ['vbscript:msgbox(1)'],
]);

it('drops obfuscated javascript: hrefs (entities, control chars, casing)', function (string $href): void {
    // The payload lives only in the href, so once the scheme is detected and the
    // href stripped, "alert" must be gone from the output entirely.
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'x',
                'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
            ]],
        ]],
    ]);

    expect(asJson($clean))->not->toContain('alert');
})->with([
    'tab-split' => ["java\tscript:alert(1)"],
    'newline-split' => ["java\nscript:alert(1)"],
    'leading-control' => ["\x01javascript:alert(1)"],
    'leading-space' => ['   javascript:alert(1)'],
    'mixed-case' => ['JaVaScRiPt:alert(1)'],
    'entity-decimal' => ['&#106;avascript:alert(1)'],
    'entity-hex' => ['&#x6A;avascript:alert(1)'],
]);

it('preserves whitelisted formatting marks and allowed attributes', function (): void {
    $clean = sanitizer()->clean([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => 'em', 'marks' => [['type' => 'italic']]],
                [
                    'type' => 'text',
                    'text' => 'a link',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://ok.test', 'target' => '_blank', 'class' => 'cta']]],
                ],
            ],
        ]],
    ]);

    $json = asJson($clean);

    expect($json)->toContain('bold')
        ->and($json)->toContain('em')
        ->and($json)->toContain('https://ok.test')
        ->and($json)->toContain('_blank')
        ->and($json)->toContain('cta');
});
