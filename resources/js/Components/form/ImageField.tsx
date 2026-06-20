import { useEffect, useId, useState } from 'react';
import FormError from '@/Components/form/FormError';

/**
 * Labelled featured-image picker (slice 002, NEWS-01/02). A native file input
 * (accept=image/*) with an accessible label, hint, error wiring, and a live
 * preview — the freshly-selected file, or an existing `currentUrl` (the article's
 * stored image on the Edit screen). Selecting a new file supersedes the existing
 * one; a "remove selection" control clears back to the existing image.
 *
 * Accessibility (WCAG 2.2 AA): explicit <label htmlFor>, `aria-describedby` →
 * hint + error, `aria-invalid` on error, the preview image carries meaningful
 * alt text. Themed magenta, dark/light aware. The selected File is handed up so
 * the parent attaches it to its Inertia form (multipart) under `featured_image`.
 */
interface ImageFieldProps {
    label: string;
    /** The newly-selected file, if any. */
    value: File | null;
    onChange: (file: File | null) => void;
    /** Already-stored image URL (Edit screen); shown when no new file is picked. */
    currentUrl?: string | null;
    /** Already-translated alt text for the preview. */
    previewAlt: string;
    /** Already-translated "remove selection" control label. */
    removeLabel: string;
    error?: string;
    required?: boolean;
    hint?: string;
}

export default function ImageField({
    label,
    value,
    onChange,
    currentUrl,
    previewAlt,
    removeLabel,
    error,
    required = false,
    hint,
}: ImageFieldProps) {
    const inputId = useId();
    const errorId = `${inputId}-error`;
    const hintId = `${inputId}-hint`;
    const [objectUrl, setObjectUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!value) {
            setObjectUrl(null);
            return;
        }
        const url = URL.createObjectURL(value);
        setObjectUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [value]);

    const describedBy =
        [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    const previewSrc = objectUrl ?? currentUrl ?? null;

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

            {previewSrc ? (
                <div className="mb-2 overflow-hidden rounded-lg border border-neutral-200 dark:border-border-dark">
                    <img
                        src={previewSrc}
                        alt={previewAlt}
                        className="max-h-48 w-full object-cover"
                    />
                </div>
            ) : null}

            <input
                id={inputId}
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                onChange={(event) => onChange(event.target.files?.[0] ?? null)}
                aria-required={required || undefined}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="block w-full text-sm text-neutral-700 file:mr-3 file:h-9 file:cursor-pointer file:rounded-lg file:border-0 file:bg-primary file:px-4 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-dark focus-visible:outline-none dark:text-slate-300"
            />

            {value ? (
                <button
                    type="button"
                    onClick={() => onChange(null)}
                    className="mt-2 self-start text-xs font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                >
                    {removeLabel}
                </button>
            ) : null}

            <FormError id={errorId} message={error} />
        </div>
    );
}
