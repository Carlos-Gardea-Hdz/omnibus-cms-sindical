import { Fragment, type ReactNode } from 'react';
import type { TipTapMark, TipTapNode } from '@/lib/tiptap';
import { sanitizeTipTap } from '@/lib/sanitizeTipTap';

/**
 * Safe renderer for stored TipTap article content (SPEC §3.3 — the public render
 * boundary). This is the SECURITY-critical render path:
 *
 *   1. The incoming content is an arbitrary JSON value (`Record<string, unknown>`
 *      over the wire). We FIRST run it through `sanitizeTipTap` — the client-side
 *      mirror of the server whitelist (defense-in-depth) — so even a poisoned row
 *      cannot carry an unsafe node/mark/attr past this point.
 *   2. We then build React elements DIRECTLY from the sanitized node tree. There
 *      is NO `dangerouslySetInnerHTML` anywhere — text becomes React text nodes
 *      (auto-escaped by React) and only whitelisted tags/marks become elements.
 *      An attacker-controlled string can therefore never become live markup.
 *
 * Only the whitelisted structure renders: paragraphs, bullet/ordered lists, list
 * items, hard breaks, and the bold/italic/underline/link marks. Links always get
 * `rel="noopener noreferrer"` and the sanitizer has already dropped unsafe
 * schemes. Themed with the magenta prose tokens, dark/light aware.
 */
interface RichTextRendererProps {
    /** Raw (untrusted) TipTap document, as delivered in the Inertia prop. */
    content: unknown;
    className?: string;
}

/** Wrap a child in the element chain implied by a text node's inline marks. */
function applyMarks(child: ReactNode, marks: TipTapMark[] | undefined, keyBase: string): ReactNode {
    if (!marks || marks.length === 0) {
        return child;
    }
    return marks.reduce<ReactNode>((acc, mark, index) => {
        const key = `${keyBase}-m${index}`;
        switch (mark.type) {
            case 'bold':
                return <strong key={key}>{acc}</strong>;
            case 'italic':
                return <em key={key}>{acc}</em>;
            case 'underline':
                return <u key={key}>{acc}</u>;
            case 'link': {
                const href = typeof mark.attrs?.href === 'string' ? mark.attrs.href : '#';
                return (
                    <a
                        key={key}
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium text-primary underline decoration-primary/40 underline-offset-2 transition-colors hover:text-primary-dark"
                    >
                        {acc}
                    </a>
                );
            }
            default:
                return acc;
        }
    }, child);
}

function renderNodes(nodes: TipTapNode[] | undefined, keyBase: string): ReactNode[] {
    return (nodes ?? []).map((node, index) => renderNode(node, `${keyBase}-${index}`));
}

function renderNode(node: TipTapNode, key: string): ReactNode {
    switch (node.type) {
        case 'text':
            return <Fragment key={key}>{applyMarks(node.text ?? '', node.marks, key)}</Fragment>;
        case 'hardBreak':
            return <br key={key} />;
        case 'paragraph':
            return (
                <p key={key} className="my-3 leading-relaxed">
                    {renderNodes(node.content, key)}
                </p>
            );
        case 'bulletList':
            return (
                <ul key={key} className="my-3 list-disc space-y-1 pl-6">
                    {renderNodes(node.content, key)}
                </ul>
            );
        case 'orderedList':
            return (
                <ol key={key} className="my-3 list-decimal space-y-1 pl-6">
                    {renderNodes(node.content, key)}
                </ol>
            );
        case 'listItem':
            return <li key={key}>{renderNodes(node.content, key)}</li>;
        default:
            // Should not occur post-sanitization; degrade to a paragraph.
            return (
                <p key={key} className="my-3 leading-relaxed">
                    {renderNodes(node.content, key)}
                </p>
            );
    }
}

export default function RichTextRenderer({ content, className }: RichTextRendererProps) {
    const safe = sanitizeTipTap(content);

    return (
        <div
            className={`text-neutral-800 dark:text-slate-200 ${className ?? ''}`}
            data-testid="article-content"
        >
            {renderNodes(safe.content, 'doc')}
        </div>
    );
}
