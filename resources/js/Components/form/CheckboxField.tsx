import { useId, type InputHTMLAttributes } from 'react';
import FormError from '@/Components/form/FormError';

/**
 * Labelled checkbox — the boolean sibling of TextField, wired for the same
 * accessibility contract (WCAG 2.2 AA): explicit <label htmlFor>,
 * `aria-describedby` for hint/error, `aria-invalid` on error, visible focus ring,
 * ≥ 44px touch target on the wrapping label. Themed with the magenta palette
 * (SPEC §1.4/§1.5), dark/light aware. Used for the representative `is_coordinator`
 * flag.
 */
type NativeProps = Omit<
    InputHTMLAttributes<HTMLInputElement>,
    'id' | 'type' | 'checked' | 'onChange' | 'className'
>;

interface CheckboxFieldProps extends NativeProps {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
    hint?: string;
}

export default function CheckboxField({
    label,
    checked,
    onChange,
    error,
    hint,
    ...rest
}: CheckboxFieldProps) {
    const inputId = useId();
    const errorId = `${inputId}-error`;
    const hintId = `${inputId}-hint`;

    const describedBy =
        [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col">
            <label
                htmlFor={inputId}
                className="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium text-neutral-800 dark:text-slate-200"
            >
                <input
                    id={inputId}
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className="h-5 w-5 rounded border-neutral-300 text-primary accent-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:bg-surface-dark"
                    {...rest}
                />
                {label}
            </label>

            {hint ? (
                <span id={hintId} className="mt-1 text-xs text-neutral-500 dark:text-slate-400">
                    {hint}
                </span>
            ) : null}

            <FormError id={errorId} message={error} />
        </div>
    );
}
