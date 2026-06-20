/**
 * Minimal, dependency-free TipTap-document model + (de)serialization for the CMS
 * rich-text editor (slice 002, NEWS-01). TipTap's persisted format is a JSON tree
 * `{ type: 'doc', content: ProseMirrorNode[] }`; we model only the node/mark types
 * the SPEC §3.3 whitelist allows, and provide:
 *
 *   - `docToHtml`   — render a (already-sanitized) doc to an HTML string for the
 *                     contentEditable surface and the public renderer;
 *   - `htmlToDoc`   — parse the contentEditable surface back into a doc, keeping
 *                     ONLY whitelisted structure (the editor never emits unsafe
 *                     nodes; the server sanitizer is still authoritative on store);
 *   - `EMPTY_DOC`   — a structurally-valid empty document;
 *   - `docHasBody`  — whether a doc carries any renderable body (publish guard UX).
 *
 * NOTE: HTML produced here is for OUR controlled, whitelisted node set only. The
 * security boundary is `sanitizeTipTap` (applied to any doc that originated from
 * untrusted input) — never trust this module to neutralize hostile HTML.
 */

/**
 * Attribute values are restricted to FormData-convertible primitives so a
 * TipTapDoc satisfies Inertia's `FormDataType` constraint when it rides in a
 * useForm payload. Our whitelist only ever carries string attrs (href/target).
 */
export type TipTapAttrValue = string | number | boolean | null;

export interface TipTapMark {
    type: string;
    attrs?: Record<string, TipTapAttrValue>;
}

export interface TipTapNode {
    type: string;
    text?: string;
    marks?: TipTapMark[];
    attrs?: Record<string, TipTapAttrValue>;
    content?: TipTapNode[];
}

export interface TipTapDoc {
    type: 'doc';
    content: TipTapNode[];
}

/** A structurally-valid empty document (one empty paragraph). */
export const EMPTY_DOC: TipTapDoc = {
    type: 'doc',
    content: [{ type: 'paragraph' }],
};

/** Block-level node types the editor renders/serializes. */
const BLOCK_TAGS: Record<string, string> = {
    paragraph: 'p',
    bulletList: 'ul',
    orderedList: 'ol',
    listItem: 'li',
};

/** Mark types → inline tag. `link` is special-cased (needs href/target). */
const MARK_TAGS: Record<string, string> = {
    bold: 'strong',
    italic: 'em',
    underline: 'u',
};

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escapeAttr(value: string): string {
    return escapeHtml(value);
}

/** Is this a renderable doc with at least one non-empty body node? */
export function docHasBody(doc: TipTapDoc | null | undefined): boolean {
    if (!doc || !Array.isArray(doc.content) || doc.content.length === 0) {
        return false;
    }
    return doc.content.some((node) => nodeHasText(node) || isStructuralBlock(node));
}

function isStructuralBlock(node: TipTapNode): boolean {
    return node.type === 'bulletList' || node.type === 'orderedList';
}

function nodeHasText(node: TipTapNode): boolean {
    if (node.type === 'text') {
        return typeof node.text === 'string' && node.text.trim().length > 0;
    }
    return (node.content ?? []).some(nodeHasText);
}

/** Render the inline marks of a text node, innermost first. */
function renderText(node: TipTapNode): string {
    let html = escapeHtml(node.text ?? '');
    for (const mark of node.marks ?? []) {
        if (mark.type === 'link') {
            const href = typeof mark.attrs?.href === 'string' ? mark.attrs.href : '#';
            const target = typeof mark.attrs?.target === 'string' ? mark.attrs.target : '_blank';
            // rel hardening for new-tab links (no reverse tabnabbing).
            html = `<a href="${escapeAttr(href)}" target="${escapeAttr(target)}" rel="noopener noreferrer">${html}</a>`;
            continue;
        }
        const tag = MARK_TAGS[mark.type];
        if (tag) {
            html = `<${tag}>${html}</${tag}>`;
        }
    }
    return html;
}

function renderNode(node: TipTapNode): string {
    if (node.type === 'text') {
        return renderText(node);
    }
    if (node.type === 'hardBreak') {
        return '<br />';
    }
    const inner = (node.content ?? []).map(renderNode).join('');
    const tag = BLOCK_TAGS[node.type];
    if (tag) {
        return `<${tag}>${inner || (tag === 'p' ? '<br />' : '')}</${tag}>`;
    }
    // Unknown block: degrade to a paragraph so structure stays valid.
    return `<p>${inner}</p>`;
}

/** Serialize a doc to an HTML string (whitelisted node set only). */
export function docToHtml(doc: TipTapDoc | null | undefined): string {
    if (!doc || !Array.isArray(doc.content) || doc.content.length === 0) {
        return '<p><br /></p>';
    }
    return doc.content.map(renderNode).join('');
}

const INLINE_MARK_FOR_TAG: Record<string, string> = {
    STRONG: 'bold',
    B: 'bold',
    EM: 'italic',
    I: 'italic',
    U: 'underline',
};

/** Walk a DOM subtree, collecting text nodes with their active inline marks. */
function collectInline(node: Node, marks: TipTapMark[]): TipTapNode[] {
    if (node.nodeType === Node.TEXT_NODE) {
        const text = node.textContent ?? '';
        if (text.length === 0) {
            return [];
        }
        return [{ type: 'text', text, ...(marks.length ? { marks: [...marks] } : {}) }];
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
        return [];
    }
    const el = node as HTMLElement;
    if (el.tagName === 'BR') {
        return [{ type: 'hardBreak' }];
    }

    let nextMarks = marks;
    const markType = INLINE_MARK_FOR_TAG[el.tagName];
    if (markType) {
        nextMarks = [...marks, { type: markType }];
    } else if (el.tagName === 'A') {
        const href = el.getAttribute('href') ?? '';
        nextMarks = [
            ...marks,
            { type: 'link', attrs: { href, target: el.getAttribute('target') ?? '_blank' } },
        ];
    }

    const out: TipTapNode[] = [];
    el.childNodes.forEach((child) => out.push(...collectInline(child, nextMarks)));
    return out;
}

function parseBlock(el: HTMLElement): TipTapNode | null {
    switch (el.tagName) {
        case 'P':
        case 'DIV': {
            const content = collectInline(el, []);
            return { type: 'paragraph', ...(content.length ? { content } : {}) };
        }
        case 'UL':
        case 'OL': {
            const items: TipTapNode[] = [];
            el.querySelectorAll(':scope > li').forEach((li) => {
                const content = collectInline(li, []);
                items.push({
                    type: 'listItem',
                    content: [{ type: 'paragraph', ...(content.length ? { content } : {}) }],
                });
            });
            return {
                type: el.tagName === 'UL' ? 'bulletList' : 'orderedList',
                content: items,
            };
        }
        default: {
            // Unknown top-level element: fold its text into a paragraph.
            const content = collectInline(el, []);
            return content.length ? { type: 'paragraph', content } : null;
        }
    }
}

/**
 * Parse the contentEditable surface's innerHTML back into a TipTap doc, keeping
 * only the whitelisted block/inline structure. Always returns a valid doc.
 */
export function htmlToDoc(html: string): TipTapDoc {
    if (typeof document === 'undefined') {
        return EMPTY_DOC;
    }
    const container = document.createElement('div');
    container.innerHTML = html;

    const content: TipTapNode[] = [];
    container.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            const text = child.textContent ?? '';
            if (text.trim().length > 0) {
                content.push({ type: 'paragraph', content: [{ type: 'text', text }] });
            }
            return;
        }
        if (child.nodeType === Node.ELEMENT_NODE) {
            const block = parseBlock(child as HTMLElement);
            if (block) {
                content.push(block);
            }
        }
    });

    return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] };
}
