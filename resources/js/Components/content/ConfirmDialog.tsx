import { useEffect, useRef } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Accessible confirm dialog for destructive Content actions (slice 002) — delete
 * article / delete category. CMS-themed (magenta / neutral, slice-001 language).
 *
 * Accessibility: focus moves to the safe default (cancel) on open, Escape +
 * backdrop click close, role="dialog" / aria-modal / aria-labelledby /
 * aria-describedby. Presentational — the parent owns the request and passes
 * `processing` so the confirm button disables while in flight. Copy is passed
 * already-translated.
 */
interface ConfirmDialogProps {
    title: string;
    message: string;
    /** Already-translated confirm-button label (defaults to "Delete"). */
    confirmLabel?: string;
    processing: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

export default function ConfirmDialog({
    title,
    message,
    confirmLabel,
    processing,
    onConfirm,
    onCancel,
}: ConfirmDialogProps) {
    const { t } = useLocale();
    const cancelRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        cancelRef.current?.focus();
    }, []);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
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
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-title"
                aria-describedby="confirm-body"
                className="relative w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-xl dark:border-border-dark dark:bg-surface-dark"
            >
                <h2
                    id="confirm-title"
                    className="text-lg font-semibold text-neutral-900 dark:text-slate-100"
                >
                    {title}
                </h2>
                <p id="confirm-body" className="mt-1 text-sm text-neutral-600 dark:text-slate-400">
                    {message}
                </p>

                <div className="mt-6 flex justify-end gap-2">
                    <button
                        ref={cancelRef}
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-11 items-center rounded-lg border border-neutral-300 px-4 font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                    >
                        {t('content.cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={processing}
                        className="inline-flex h-11 items-center rounded-lg bg-danger px-6 font-semibold text-white transition-colors hover:bg-danger-dark focus-visible:ring-2 focus-visible:ring-danger/50 focus-visible:outline-none disabled:opacity-60"
                    >
                        {confirmLabel ?? t('content.delete')}
                    </button>
                </div>
            </div>
        </div>
    );
}
