import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import LogoutButton from '@/Components/LogoutButton';

/**
 * Authed admin page chrome for the Content slice (SPEC §7.2, §10.1) — the same
 * shell language as Admin/Dashboard.tsx: a sticky branded header with the
 * LanguageSwitcher + DarkModeToggle + LogoutButton, a single #main landmark,
 * one <h1>, and an optional "back" link so no admin screen is a dead-end
 * (WCAG 2.2 AA: real links, one main, one h1).
 *
 * It also surfaces the server flash banner (success / error) that mutations send
 * via `back()->with('success'|'error', …)`. `flash` is read defensively from the
 * shared props so a page never crashes if a response omits it. The banner uses
 * role="status" for success and role="alert" for errors (assertive) — WCAG 2.2
 * status messages. Themed magenta, dark/light aware.
 */
interface AdminShellProps {
    /** Already-translated document <title> + <h1>. */
    title: string;
    /** Already-translated lead paragraph (optional). */
    subtitle?: string;
    /** When set, a back link renders above the <h1>. */
    backHref?: string;
    /** Already-translated back-link label. */
    backLabel?: string;
    /** Right-aligned toolbar slot (e.g. a "New article" action). */
    toolbar?: ReactNode;
    children: ReactNode;
}

export default function AdminShell({
    title,
    subtitle,
    backHref,
    backLabel,
    toolbar,
    children,
}: AdminShellProps) {
    const { t } = useLocale();
    const flash = usePage<PageProps>().props.flash;

    return (
        <>
            <Head title={title} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <Link
                            href="/admin/dashboard"
                            className="flex items-center gap-2 text-lg font-extrabold tracking-tight"
                        >
                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary-dark text-white">
                                C
                            </span>
                            <span className="text-gradient-primary">{t('app.name')}</span>
                        </Link>
                        <nav className="flex items-center gap-2" aria-label={t('nav.utilities')}>
                            <LanguageSwitcher />
                            <DarkModeToggle />
                            <LogoutButton />
                        </nav>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-6xl px-6 py-12">
                    {backHref ? (
                        <Link
                            href={backHref}
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                        >
                            <span aria-hidden="true">&larr;</span>
                            {backLabel ?? t('content.back')}
                        </Link>
                    ) : null}

                    <div
                        className={`flex flex-wrap items-end justify-between gap-4 ${
                            backHref ? 'mt-3' : ''
                        }`}
                    >
                        <div>
                            <h1 className="text-3xl font-extrabold tracking-tight">{title}</h1>
                            {subtitle ? (
                                <p className="mt-2 max-w-2xl text-neutral-600 dark:text-slate-400">
                                    {subtitle}
                                </p>
                            ) : null}
                        </div>
                        {toolbar ? <div className="flex items-center gap-2">{toolbar}</div> : null}
                    </div>

                    {flash?.success ? (
                        <p
                            role="status"
                            className="mt-6 rounded-lg border border-primary/30 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-dark dark:bg-primary/10 dark:text-primary-light"
                        >
                            {flash.success}
                        </p>
                    ) : null}
                    {flash?.error ? (
                        <p
                            role="alert"
                            className="mt-6 rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-medium text-danger"
                        >
                            {flash.error}
                        </p>
                    ) : null}

                    <div className="mt-8">{children}</div>
                </main>
            </div>
        </>
    );
}
