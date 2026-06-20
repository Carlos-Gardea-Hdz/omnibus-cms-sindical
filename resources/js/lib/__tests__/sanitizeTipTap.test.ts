import { describe, expect, it } from 'vitest';
import { sanitizeTipTap } from '@/lib/sanitizeTipTap';
import { docToHtml, type TipTapDoc } from '@/lib/tiptap';
import type { TipTapNode } from '@/lib/tiptap';

/*
 * Client-side stored-XSS defense (CONTRACT §5/§10, SPEC §3.3). The SERVER
 * sanitizer is authoritative, but this is the defense-in-depth render boundary:
 * even a poisoned JSONB row must never produce executable markup. We feed hostile
 * TipTap docs through `sanitizeTipTap` and assert (a) the dangerous node/mark/attr
 * is gone from the tree, and (b) the serialized HTML carries no executable token.
 */

function collectTypes(doc: TipTapDoc): Set<string> {
    const types = new Set<string>();
    const walk = (node: TipTapNode): void => {
        types.add(node.type);
        (node.marks ?? []).forEach((mark) => types.add(`mark:${mark.type}`));
        (node.content ?? []).forEach(walk);
    };
    doc.content.forEach(walk);
    return types;
}

describe('sanitizeTipTap', () => {
    it('drops a script node entirely', () => {
        const hostile = {
            type: 'doc',
            content: [
                { type: 'script', content: [{ type: 'text', text: 'alert(1)' }] },
                { type: 'paragraph', content: [{ type: 'text', text: 'safe copy' }] },
            ],
        };

        const clean = sanitizeTipTap(hostile);
        const types = collectTypes(clean);

        expect(types.has('script')).toBe(false);
        const html = docToHtml(clean);
        expect(html).not.toContain('script');
        expect(html).toContain('safe copy');
    });

    it('strips on* handlers, style and class attributes (only href/target survive)', () => {
        const hostile = {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    attrs: { onclick: 'steal()', style: 'color:red', class: 'x' },
                    content: [
                        {
                            type: 'text',
                            text: 'bold',
                            marks: [{ type: 'bold', attrs: { onmouseover: 'x()' } }],
                        },
                    ],
                },
            ],
        };

        const clean = sanitizeTipTap(hostile);
        const html = docToHtml(clean);

        expect(html).not.toMatch(/on\w+=/i);
        expect(html).not.toContain('style');
        expect(html).not.toContain('steal');
        expect(html).toContain('<strong>bold</strong>');
        // The reconstructed paragraph carries no attrs at all.
        expect(clean.content[0]?.attrs).toBeUndefined();
    });

    it('drops a javascript: href but keeps the link text', () => {
        const hostile = {
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [
                        {
                            type: 'text',
                            text: 'click me',
                            marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }],
                        },
                    ],
                },
            ],
        };

        const clean = sanitizeTipTap(hostile);
        const types = collectTypes(clean);
        const html = docToHtml(clean);

        expect(types.has('mark:link')).toBe(false);
        expect(html).not.toContain('javascript:');
        expect(html).toContain('click me');
    });

    it('also drops data: and vbscript: hrefs', () => {
        for (const scheme of ['data:text/html,<script>', 'vbscript:msgbox']) {
            const clean = sanitizeTipTap({
                type: 'doc',
                content: [
                    {
                        type: 'paragraph',
                        content: [
                            {
                                type: 'text',
                                text: 'x',
                                marks: [{ type: 'link', attrs: { href: scheme } }],
                            },
                        ],
                    },
                ],
            });
            expect(collectTypes(clean).has('mark:link')).toBe(false);
        }
    });

    it('preserves whitelisted content (p, strong, safe https link)', () => {
        const clean = sanitizeTipTap({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [
                        { type: 'text', text: 'hello ' },
                        { type: 'text', text: 'world', marks: [{ type: 'bold' }] },
                        {
                            type: 'text',
                            text: ' link',
                            marks: [{ type: 'link', attrs: { href: 'https://example.com' } }],
                        },
                    ],
                },
            ],
        });

        const types = collectTypes(clean);
        const html = docToHtml(clean);

        expect(types.has('paragraph')).toBe(true);
        expect(types.has('mark:bold')).toBe(true);
        expect(types.has('mark:link')).toBe(true);
        expect(html).toContain('href="https://example.com"');
        expect(html).toContain('rel="noopener noreferrer"');
    });

    it('returns a structurally valid empty doc for non-doc / fully-stripped input', () => {
        expect(sanitizeTipTap(null)).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] });
        expect(sanitizeTipTap('nope')).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] });

        const onlyHostile = sanitizeTipTap({
            type: 'doc',
            content: [{ type: 'iframe', attrs: { src: 'evil' } }],
        });
        expect(onlyHostile.type).toBe('doc');
        expect(onlyHostile.content.length).toBeGreaterThan(0);
    });
});
