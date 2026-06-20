import { useEffect, useRef, type ReactNode } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Accessible create/edit modal shell for Content catalog forms (slice 002) — used
 * by the Category management screen. CMS-themed (magenta / neutral, slice-001
 * language). The parent owns the <form> + its useForm state and passes the
 * labelled fields as children plus the submit handler.
 *
 * Accessibility: the first focusable field is focused on open, Escape + backdrop
 * click close, role="dialog" / aria-modal / aria-labelledby. `mode` only switches
 * the title + submit label (create vs edit); one DTO serves store + update.
 */
interface FormModalProps {
    mode: 'create' | 'edit';
    /** Already-translated subject (e.g. the catalog name) shown after the verb. */
    subject: string;
    processing: boolean;
    onSubmit: (event: React.FormEvent) => void;
    onCancel: () => void;
    children: ReactNode;
}

export default function FormModal({
    mode,
    subject,
    processing,
    onSubmit,
    onCancel,
    children,
}: FormModalProps) {
    const { t } = useLocale();
    const dialogRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const focusable = dialogRef.current?.querySelector<HTMLElement>(
            'input, select, textarea, button',
        );
        focusable?.focus();
    }, []);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 py-8"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    onCancel();
                }
            }}
        >
            <button
                type="button"
                aria-label={t('content.cancel')}
                className="absolute inset-0 h-full w-full cursor-default"
                onClick={onCancel}
            />
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="content-form-title"
                className="relative max-h-full w-full max-w-lg overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 shadow-xl dark:border-border-dark dark:bg-surface-dark"
            >
                <h2
                    id="content-form-title"
                    className="text-lg font-semibold text-neutral-900 dark:text-slate-100"
                >
                    {mode === 'create' ? t('content.new') : t('content.edit')}
                    <span className="ml-1 font-normal text-neutral-500 dark:text-slate-400">
                        · {subject}
                    </span>
                </h2>

                <form onSubmit={onSubmit} noValidate className="mt-4 flex flex-col gap-4">
                    {children}

                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onCancel}
                            className="inline-flex h-11 items-center rounded-lg border border-neutral-300 px-4 font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                        >
                            {t('content.cancel')}
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex h-11 items-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                        >
                            {t('content.save')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
