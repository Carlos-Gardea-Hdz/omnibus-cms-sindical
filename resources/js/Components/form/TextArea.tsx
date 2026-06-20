import { useId, type TextareaHTMLAttributes } from 'react';
import FormError from '@/Components/form/FormError';

/**
 * Labelled multi-line text input — the textarea sibling of TextField, wired for
 * the same accessibility contract (WCAG 2.2 AA):
 * - explicit <label htmlFor> association
 * - `aria-describedby` → hint + error nodes, `aria-invalid` on error
 * - `aria-required` mirrors the required flag
 * - visible focus ring via :focus-visible
 *
 * Themed with the magenta palette (SPEC §1.4/§1.5), dark/light aware. Used for
 * subtitle / meta description and other free-text article fields.
 */
type NativeProps = Omit<
    TextareaHTMLAttributes<HTMLTextAreaElement>,
    'id' | 'value' | 'onChange' | 'className'
>;

interface TextAreaProps extends NativeProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    required?: boolean;
    hint?: string;
}

export default function TextArea({
    label,
    value,
    onChange,
    error,
    required = false,
    hint,
    rows = 3,
    ...rest
}: TextAreaProps) {
    const inputId = useId();
    const errorId = `${inputId}-error`;
    const hintId = `${inputId}-hint`;

    const describedBy =
        [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col">
            <label
                htmlFor={inputId}
                className="mb-1 text-sm font-medium text-neutral-800 dark:text-slate-200"
            >
                {label}
                {required ? (
                    <span aria-hidden="true" className="ml-0.5 text-danger">
                        *
                    </span>
                ) : null}
            </label>

            {hint ? (
                <span id={hintId} className="mb-1 text-xs text-neutral-500 dark:text-slate-400">
                    {hint}
                </span>
            ) : null}

            <textarea
                id={inputId}
                rows={rows}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                aria-required={required || undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="resize-y rounded-lg border border-neutral-300 bg-white px-3 py-2 text-neutral-900 transition-colors placeholder:text-neutral-400 focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none disabled:opacity-60 aria-[invalid=true]:border-danger dark:border-border-dark dark:bg-surface-dark dark:text-slate-100 dark:placeholder:text-slate-500"
                {...rest}
            />

            <FormError id={errorId} message={error} />
        </div>
    );
}
