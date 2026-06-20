import type { TipTapDoc, TipTapMark, TipTapNode } from '@/lib/tiptap';
import { EMPTY_DOC } from '@/lib/tiptap';

/**
 * Client-side TipTap sanitizer — defense-in-depth mirror of the server's
 * `SanitizesContent` service and the SPEC §3.3 whitelist. The SERVER sanitizer
 * is authoritative (content is cleaned before it ever reaches the DB); this runs
 * on the public render path so that even a hypothetically-poisoned row can never
 * produce executable markup in the browser. It is an allow-list, not a
 * block-list.
 *
 *   - Allowed node types: doc, paragraph, text, hardBreak, bulletList,
 *     orderedList, listItem, plus heading (folded → paragraph). Any other node
 *     type is dropped; its text descendants are preserved inline.
 *   - Allowed mark types: bold, italic, underline, link. Any other mark dropped.
 *   - Allowed attributes: only `href` + `target` survive (on the `link` mark);
 *     every other attr — every `on*` handler, every `style`, every `class`
 *     carrying script — is discarded by reconstruction (we copy only the keys we
 *     trust, never spread the original attrs).
 *   - `href` scheme guard: drops javascript:/data:/vbscript: hrefs (the link
 *     mark is removed, text kept). Allows http/https/mailto/relative.
 *
 * Returns a structurally-valid `{type:'doc',content:[…]}` even when everything
 * was stripped.
 */

const ALLOWED_BLOCKS = new Set(['paragraph', 'bulletList', 'orderedList', 'listItem', 'hardBreak']);

const ALLOWED_MARKS = new Set(['bold', 'italic', 'underline', 'link']);

const UNSAFE_SCHEME = /^\s*(javascript|data|vbscript):/i;

function safeHref(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const trimmed = value.trim();
    if (trimmed.length === 0 || UNSAFE_SCHEME.test(trimmed)) {
        return null;
    }
    return trimmed;
}

function sanitizeMarks(marks: TipTapMark[] | undefined): TipTapMark[] {
    if (!Array.isArray(marks)) {
        return [];
    }
    const out: TipTapMark[] = [];
    for (const mark of marks) {
        if (!mark || typeof mark.type !== 'string' || !ALLOWED_MARKS.has(mark.type)) {
            continue;
        }
        if (mark.type === 'link') {
            const href = safeHref(mark.attrs?.href);
            if (!href) {
                // Unsafe/empty href → drop the link mark entirely (keep the text).
                continue;
            }
            out.push({ type: 'link', attrs: { href, target: '_blank' } });
            continue;
        }
        // Reconstruct with type only — no attrs survive on formatting marks.
        out.push({ type: mark.type });
    }
    return out;
}

function sanitizeNode(node: TipTapNode | null | undefined): TipTapNode[] {
    if (!node || typeof node.type !== 'string') {
        return [];
    }

    if (node.type === 'text') {
        const text = typeof node.text === 'string' ? node.text : '';
        if (text.length === 0) {
            return [];
        }
        const marks = sanitizeMarks(node.marks);
        return [{ type: 'text', text, ...(marks.length ? { marks } : {}) }];
    }

    if (node.type === 'hardBreak') {
        return [{ type: 'hardBreak' }];
    }

    const children = (node.content ?? []).flatMap(sanitizeNode);

    // Headings degrade to paragraphs (whitelist maps heading→p).
    if (node.type === 'heading') {
        return [{ type: 'paragraph', ...(children.length ? { content: children } : {}) }];
    }

    if (ALLOWED_BLOCKS.has(node.type)) {
        return [{ type: node.type, ...(children.length ? { content: children } : {}) }];
    }

    // Any non-whitelisted node type (script, html, image, table, …) is dropped,
    // but its sanitized text children are kept inline so copy isn't lost.
    return children;
}

/** Sanitize an untrusted TipTap doc into a safe, structurally-valid copy. */
export function sanitizeTipTap(value: unknown): TipTapDoc {
    if (
        !value ||
        typeof value !== 'object' ||
        (value as { type?: unknown }).type !== 'doc' ||
        !Array.isArray((value as { content?: unknown }).content)
    ) {
        return EMPTY_DOC;
    }

    const content = (value as TipTapDoc).content.flatMap(sanitizeNode);
    return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] };
}
