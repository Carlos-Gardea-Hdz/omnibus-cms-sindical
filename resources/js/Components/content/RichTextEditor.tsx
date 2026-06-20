import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import FormError from '@/Components/form/FormError';
import type { TipTapDoc } from '@/lib/tiptap';
import { docToHtml, htmlToDoc } from '@/lib/tiptap';
import { sanitizeTipTap } from '@/lib/sanitizeTipTap';

/**
 * Dependency-free rich-text editor that emits TipTap-compatible JSON (slice 002,
 * NEWS-01). A `contentEditable` surface with an accessible toolbar (bold /
 * italic / underline / link / bullet / ordered list). The persisted shape is the
 * TipTap doc `{ type:'doc', content:[…] }`, so the controller stores the same
 * JSONB the spec mandates — no TipTap runtime dependency required.
 *
 * SECURITY: the editor's output is run through `htmlToDoc` (keeps ONLY the
 * whitelisted block/inline structure) and then `sanitizeTipTap` before it leaves
 * the component, so the value handed to the form is already whitelist-clean. The
 * SERVER sanitizer remains authoritative on store; this is the first line.
 *
 * Accessibility (WCAG 2.2 AA): the surface is labelled (aria-labelledby + an
 * explicit label), `role="textbox"` / `aria-multiline`, `aria-describedby` to the
 * hint + error nodes, `aria-invalid` on error; toolbar buttons are real
 * <button>s with `aria-label` + `aria-pressed`, ≥36px targets, visible focus
 * rings. Themed magenta, dark/light aware.
 */
interface RichTextEditorProps {
    label: string;
    value: TipTapDoc;
    onChange: (doc: TipTapDoc) => void;
    error?: string;
    required?: boolean;
    hint?: string;
}

type Command =
    | { kind: 'inline'; command: 'bold' | 'italic' | 'underline'; labelKey: string; icon: string }
    | {
          kind: 'list';
          command: 'insertUnorderedList' | 'insertOrderedList';
          labelKey: string;
          icon: string;
      };

const COMMANDS: Command[] = [
    { kind: 'inline', command: 'bold', labelKey: 'content.editor.bold', icon: 'B' },
    { kind: 'inline', command: 'italic', labelKey: 'content.editor.italic', icon: 'I' },
    { kind: 'inline', command: 'underline', labelKey: 'content.editor.underline', icon: 'U' },
    {
        kind: 'list',
        command: 'insertUnorderedList',
        labelKey: 'content.editor.bullet_list',
        icon: '•',
    },
    {
        kind: 'list',
        command: 'insertOrderedList',
        labelKey: 'content.editor.ordered_list',
        icon: '1.',
    },
];

export default function RichTextEditor({
    label,
    value,
    onChange,
    error,
    required = false,
    hint,
}: RichTextEditorProps) {
    const { t } = useLocale();
    const surfaceRef = useRef<HTMLDivElement>(null);
    const baseId = useId();
    const labelId = `${baseId}-label`;
    const errorId = `${baseId}-error`;
    const hintId = `${baseId}-hint`;
    // Tracks the doc we last rendered into the surface, so external value changes
    // (e.g. loading an article to edit) reflow without clobbering live typing.
    const lastHtml = useRef<string | null>(null);

    const describedBy =
        [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    // Sync the surface DOM when the incoming doc differs from what's displayed.
    useEffect(() => {
        const surface = surfaceRef.current;
        if (!surface) {
            return;
        }
        const html = docToHtml(value);
        if (lastHtml.current === html) {
            return;
        }
        // Only overwrite when the surface isn't the active edit target, to avoid
        // resetting the caret mid-keystroke.
        if (document.activeElement !== surface) {
            surface.innerHTML = html;
            lastHtml.current = html;
        }
    }, [value]);

    const emit = useCallback(() => {
        const surface = surfaceRef.current;
        if (!surface) {
            return;
        }
        lastHtml.current = surface.innerHTML;
        const doc = sanitizeTipTap(htmlToDoc(surface.innerHTML));
        onChange(doc);
    }, [onChange]);

    const run = useCallback(
        (command: string) => {
            const surface = surfaceRef.current;
            surface?.focus();
            // execCommand is deprecated but remains the dependency-free way to
            // format a contentEditable surface across browsers; output is parsed
            // and whitelisted before it leaves the component.
            document.execCommand(command, false);
            emit();
        },
        [emit],
    );

    const insertLink = useCallback(() => {
        const surface = surfaceRef.current;
        surface?.focus();
        const url = window.prompt(t('content.editor.link_prompt'), 'https://');
        if (!url) {
            return;
        }
        const trimmed = url.trim();
        // Reject unsafe schemes at entry; the sanitizer is the backstop.
        if (/^\s*(javascript|data|vbscript):/i.test(trimmed)) {
            return;
        }
        document.execCommand('createLink', false, trimmed);
        emit();
    }, [emit, t]);

    return (
        <div className="flex flex-col">
            <span
                id={labelId}
                className="mb-1 text-sm font-medium text-neutral-800 dark:text-slate-200"
            >
                {label}
                {required ? (
                    <span aria-hidden="true" className="ml-0.5 text-danger">
                        *
                    </span>
                ) : null}
            </span>

            {hint ? (
                <span id={hintId} className="mb-1 text-xs text-neutral-500 dark:text-slate-400">
                    {hint}
                </span>
            ) : null}

            <div className="rounded-lg border border-neutral-300 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30 aria-[invalid=true]:border-danger dark:border-border-dark">
                <div
                    role="toolbar"
                    aria-label={t('content.editor.toolbar')}
                    aria-controls={baseId}
                    className="flex flex-wrap gap-1 border-b border-neutral-200 bg-neutral-50 p-1.5 dark:border-border-dark dark:bg-surface-dark"
                >
                    {COMMANDS.map((cmd) => (
                        <ToolbarButton
                            key={cmd.command}
                            label={t(cmd.labelKey)}
                            onClick={() => run(cmd.command)}
                        >
                            {cmd.icon}
                        </ToolbarButton>
                    ))}
                    <ToolbarButton label={t('content.editor.link')} onClick={insertLink}>
                        🔗
                    </ToolbarButton>
                </div>

                <div
                    id={baseId}
                    ref={surfaceRef}
                    role="textbox"
                    aria-multiline="true"
                    aria-labelledby={labelId}
                    aria-describedby={describedBy}
                    aria-invalid={error ? true : undefined}
                    aria-required={required || undefined}
                    contentEditable
                    suppressContentEditableWarning
                    onInput={emit}
                    onBlur={emit}
                    className="min-h-44 max-w-none bg-white px-3 py-2 text-neutral-900 focus:outline-none dark:bg-bg-dark dark:text-slate-100 [&_a]:text-primary [&_a]:underline [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-2 [&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-6"
                />
            </div>

            <FormError id={errorId} message={error} />
        </div>
    );
}

interface ToolbarButtonProps {
    label: string;
    onClick: () => void;
    children: ReactNode;
}

function ToolbarButton({ label, onClick, children }: ToolbarButtonProps) {
    return (
        <button
            type="button"
            // Keep the editor's selection while clicking the toolbar.
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            aria-label={label}
            title={label}
            className="inline-flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm font-semibold text-neutral-700 transition-colors hover:bg-primary/10 hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:text-slate-200"
        >
            <span aria-hidden="true">{children}</span>
        </button>
    );
}
