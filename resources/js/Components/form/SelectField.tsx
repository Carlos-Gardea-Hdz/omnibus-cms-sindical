import { useId, type SelectHTMLAttributes } from 'react';
import FormError from '@/Components/form/FormError';

export interface SelectOption {
    value: string | number;
    label: string;
}

/**
 * Labelled <select> — the dropdown sibling of TextField, wired for the same
 * accessibility contract (WCAG 2.2 AA): explicit <label htmlFor>,
 * `aria-describedby` for hint/error, `aria-invalid`/`aria-required`, visible
 * focus ring. Themed with the magenta palette (SPEC §1.4/§1.5), dark/light aware.
 *
 * Used for the article category picker. `value` is coerced to a string for the
 * native control; callers map it back to their own type on change.
 */
type NativeProps = Omit<
    SelectHTMLAttributes<HTMLSelectElement>,
    'id' | 'value' | 'onChange' | 'className'
>;

interface SelectFieldProps extends NativeProps {
    label: string;
    value: string | number | '';
    onChange: (value: string) => void;
    options: SelectOption[];
    /** Optional leading placeholder (disabled, e.g. "Select a category…"). */
    placeholder?: string;
    error?: string;
    required?: boolean;
    hint?: string;
}

export default function SelectField({
    label,
    value,
    onChange,
    options,
    placeholder,
    error,
    required = false,
    hint,
    ...rest
}: SelectFieldProps) {
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

            <select
                id={inputId}
                value={value === '' ? '' : String(value)}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                aria-required={required || undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="h-11 rounded-lg border border-neutral-300 bg-white px-3 text-neutral-900 transition-colors focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none disabled:opacity-60 aria-[invalid=true]:border-danger dark:border-border-dark dark:bg-surface-dark dark:text-slate-100"
                {...rest}
            >
                {placeholder ? (
                    <option value="" disabled>
                        {placeholder}
                    </option>
                ) : null}
                {options.map((option) => (
                    <option key={option.value} value={String(option.value)}>
                        {option.label}
                    </option>
                ))}
            </select>

            <FormError id={errorId} message={error} />
        </div>
    );
}
