<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

/**
 * Allow-list sanitizer for TipTap/ProseMirror JSONB content (SPEC §3.3) — the
 * load-bearing stored-XSS defense. It runs in CreateArticleAction and
 * UpdateArticleAction BEFORE persistence, so the database can never hold an
 * unsafe node, attribute, or href. The show page additionally runs DOMPurify on
 * render (defense-in-depth); this service is the authoritative gate.
 *
 * It is an ALLOW-LIST, not a block-list: it walks the structured tree and keeps
 * only known-safe node types, mark types, and attributes. Anything not on a
 * whitelist is dropped — a new attack vector is unsafe by default, never by
 * omission.
 */
final class SanitizesContent
{
    /**
     * Node `type`s that survive sanitization. `heading` is normalised to
     * `paragraph` on the way through (SPEC §3.3 maps headings to <p>).
     *
     * @var list<string>
     */
    private const ALLOWED_NODE_TYPES = [
        'doc',
        'paragraph',
        'text',
        'bulletList',
        'orderedList',
        'listItem',
        'hardBreak',
    ];

    /**
     * Mark `type`s that survive sanitization (TipTap inline formatting).
     *
     * @var list<string>
     */
    private const ALLOWED_MARK_TYPES = [
        'bold',
        'italic',
        'underline',
        'link',
    ];

    /**
     * Attribute keys retained on any node or mark. Everything else — every `on*`
     * handler, every `style`, every data/aria/id — is stripped.
     *
     * @var list<string>
     */
    private const ALLOWED_ATTRS = [
        'class',
        'href',
        'target',
    ];

    /**
     * URL schemes that are NEVER allowed on an href. Relative URLs and the safe
     * schemes (http, https, mailto, tel) pass through.
     *
     * @var list<string>
     */
    private const BLOCKED_HREF_SCHEMES = [
        'javascript',
        'data',
        'vbscript',
    ];

    /**
     * Return a sanitized copy of a TipTap document. Always yields a structurally
     * valid `{ type: 'doc', content: [...] }` even if everything was stripped.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function clean(array $doc): array
    {
        $content = $doc['content'] ?? [];

        return [
            'type' => 'doc',
            'content' => is_array($content) ? $this->cleanNodes($content) : [],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function cleanNodes(array $nodes): array
    {
        $clean = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $cleaned = $this->cleanNode($node);

            if ($cleaned !== null) {
                $clean[] = $cleaned;
            }
        }

        return $clean;
    }

    /**
     * Sanitize a single node. A node whose `type` is not whitelisted is dropped
     * entirely (its raw-HTML/script payload goes with it).
     *
     * @param  array<mixed, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function cleanNode(array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (! is_string($type)) {
            return null;
        }

        // `heading` is allowed but normalised to a paragraph (SPEC §3.3).
        if ($type === 'heading') {
            $type = 'paragraph';
        }

        if (! in_array($type, self::ALLOWED_NODE_TYPES, strict: true)) {
            return null;
        }

        $clean = ['type' => $type];

        // text content — kept verbatim; it is JSON text, never interpreted as
        // markup (the renderer escapes it). Marks are sanitized separately.
        if ($type === 'text' && isset($node['text']) && is_string($node['text'])) {
            $clean['text'] = $node['text'];
        }

        $attrs = $this->cleanAttrs($node['attrs'] ?? null);
        if ($attrs !== []) {
            $clean['attrs'] = $attrs;
        }

        $marks = $this->cleanMarks($node['marks'] ?? null);
        if ($marks !== []) {
            $clean['marks'] = $marks;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $clean['content'] = $this->cleanNodes($node['content']);
        }

        return $clean;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cleanMarks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }

        $clean = [];

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $type = $mark['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::ALLOWED_MARK_TYPES, strict: true)) {
                continue;
            }

            $cleanMark = ['type' => $type];
            $attrs = $this->cleanAttrs($mark['attrs'] ?? null);
            if ($attrs !== []) {
                $cleanMark['attrs'] = $attrs;
            }

            $clean[] = $cleanMark;
        }

        return $clean;
    }

    /**
     * Keep only whitelisted attribute keys; drop every `on*` handler, `style`,
     * and any other attribute. An href with a blocked scheme is dropped.
     *
     * @return array<string, mixed>
     */
    private function cleanAttrs(mixed $attrs): array
    {
        if (! is_array($attrs)) {
            return [];
        }

        $clean = [];

        foreach ($attrs as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_ATTRS, strict: true)) {
                continue;
            }

            if ($key === 'href') {
                if (! is_string($value) || $this->hasBlockedScheme($value)) {
                    continue;
                }
            }

            // Scalars only — no nested attribute structures carry markup.
            if (is_scalar($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** True if the href's scheme is one of the blocked (XSS-capable) schemes. */
    private function hasBlockedScheme(string $href): bool
    {
        // Decode HTML entities first so an entity-encoded scheme (e.g.
        // "&#106;avascript:" / "&#x6A;avascript:") cannot slip past the allow-list
        // regardless of how the stored value is later rendered. Then strip the
        // whitespace/control chars browsers ignore ("java\tscript:", " javascript:")
        // and lowercase for the scheme compare.
        $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        $normalised = mb_strtolower(preg_replace('/[\x00-\x20]+/', '', $decoded) ?? '');

        foreach (self::BLOCKED_HREF_SCHEMES as $scheme) {
            if (str_starts_with($normalised, $scheme.':')) {
                return true;
            }
        }

        return false;
    }
}
