import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Logout control (SPEC §7.2, AUTH path) — a reusable button any authenticated
 * page can drop into its header nav (alongside LanguageSwitcher /
 * DarkModeToggle). It POSTs to `/logout` via Inertia's useForm so the request
 * carries the CSRF token and is a real state-changing POST (never a GET on a
 * link). The server destroys the session, clears the cookie and redirects to
 * the login page.
 *
 * Native <button>, labelled, keyboard-operable, ≥ 36px touch target (h-9).
 */
export default function LogoutButton() {
    const { t } = useLocale();
    const { post, processing } = useForm({});

    const submit = () => {
        post('/logout');
    };

    return (
        <button
            type="button"
            onClick={submit}
            disabled={processing}
            aria-label={t('nav.logout')}
            title={t('nav.logout')}
            className="inline-flex h-9 min-w-9 items-center justify-center rounded-lg border border-neutral-200 px-3 text-sm font-medium text-neutral-700 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none disabled:opacity-60 dark:border-border-dark dark:text-slate-200"
        >
            {t('nav.logout')}
        </button>
    );
}
