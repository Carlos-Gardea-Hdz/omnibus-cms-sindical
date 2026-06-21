import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import FormError from '@/Components/form/FormError';

/**
 * Demo launcher (CMS slice 008, AUTH-02). A guest-facing page at GET /demo that
 * lets a visitor pick a read-only demo persona and enter a 30-minute sandbox —
 * the same showcase device used in UNIGES (slice 006), mirrored for the CMS.
 *
 * Props are snake_case, matching Auth\DemoLoginController::create EXACTLY:
 *   { presets: Array<{ value, role, title_key, description_key }> }
 * where `value` is the DemoPreset backed value and is NEVER 'super_admin' (the
 * load-bearing invariant — a demo can never reach a super_admin-gated screen).
 * The server-only demo token is never sent here.
 *
 * Picking a preset POSTs { preset } to /demo-login (throttled server-side at
 * 10/hour/IP — AUTH-02). On success the server logs the ephemeral demo user in,
 * stamps the 30-min TTL, and lands on the role's dashboard. A throttle/invalid
 * preset returns 302 + a `preset` session error (NEVER 422), shown inline below.
 *
 * Each preset is a real submit <button> (no dead-ends); the chooser is theme- and
 * locale-aware (magenta palette, dark/light, WCAG 2.2 AA).
 */
interface DemoPreset {
    value: string;
    role: App.Domain.Identity.Enums.UserRole;
    title_key: string;
    description_key: string;
}

interface DemoChooserProps {
    presets: DemoPreset[];
}

export default function DemoChooser({ presets }: DemoChooserProps) {
    const { t } = useLocale();
    const { errors: pageErrors } = usePage<PageProps>().props;

    const { setData, post, processing } = useForm<{ preset: string }>({ preset: '' });

    const choose = (value: string) => {
        setData('preset', value);
        post('/demo-login', { preserveScroll: true });
    };

    // The throttle / out-of-set guard flashes onto the `preset` field (AUTH-02).
    const presetError = pageErrors?.preset;

    return (
        <>
            <Head title={t('demo.chooser.title')} />
            <div className="relative flex min-h-dvh flex-col overflow-hidden bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="flex items-center justify-between border-b border-neutral-200/70 px-6 py-4 dark:border-border-dark">
                    <a
                        href="/"
                        className="flex items-center gap-2 text-lg font-extrabold tracking-tight"
                    >
                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary-dark text-white">
                            C
                        </span>
                        <span className="text-gradient-primary">{t('app.name')}</span>
                    </a>
                    <nav className="flex items-center gap-2" aria-label={t('nav.utilities')}>
                        <LanguageSwitcher />
                        <DarkModeToggle />
                    </nav>
                </header>

                <main
                    id="main"
                    className="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-6 py-12"
                >
                    <div className="pointer-events-none absolute top-24 left-1/2 -z-10 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-primary/15 blur-3xl dark:bg-primary/10" />

                    <h1 className="text-3xl font-extrabold tracking-tight">
                        {t('demo.chooser.title')}
                    </h1>
                    <p className="mt-2 max-w-2xl text-neutral-600 dark:text-slate-400">
                        {t('demo.chooser.subtitle')}
                    </p>

                    <FormError id="demo-preset-error" message={presetError} />

                    <div className="mt-8 grid gap-4 sm:grid-cols-3" role="group" aria-label={t('demo.chooser.title')}>
                        {presets.map((preset) => (
                            <button
                                key={preset.value}
                                type="button"
                                onClick={() => choose(preset.value)}
                                disabled={processing}
                                className="group flex h-full flex-col rounded-2xl border border-neutral-200 bg-white p-5 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-primary hover:shadow-md focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60 dark:border-border-dark dark:bg-surface-dark"
                            >
                                <span className="inline-flex w-fit rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold tracking-wide text-primary-dark uppercase dark:text-primary-light">
                                    {preset.value}
                                </span>
                                <span className="mt-3 text-lg font-bold tracking-tight">
                                    {t(preset.title_key)}
                                </span>
                                <span className="mt-1 flex-1 text-sm text-neutral-600 dark:text-slate-400">
                                    {t(preset.description_key)}
                                </span>
                                <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary transition-colors group-hover:text-primary-dark">
                                    {t('demo.cta.try')}
                                    <span aria-hidden="true">&rarr;</span>
                                </span>
                            </button>
                        ))}
                    </div>

                    <p className="mt-8 text-sm text-neutral-500 dark:text-slate-400">
                        {t('demo.chooser.note')}
                    </p>

                    <Link
                        href="/login"
                        className="mt-4 inline-flex w-fit items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {t('demo.chooser.back_to_login')}
                    </Link>
                </main>
            </div>
        </>
    );
}
