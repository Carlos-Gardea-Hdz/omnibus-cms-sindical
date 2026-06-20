import { Head } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import LogoutButton from '@/Components/LogoutButton';

/**
 * Authed admin shell (SPEC §7.2, §10.1) — the minimal post-login landing for all
 * four roles this slice. Props are snake_case and match the DashboardController
 * payload EXACTLY: { username, role, role_label_key }. The role badge resolves its
 * label client-side via `t(role_label_key)` (the enum's labelKey() target, e.g.
 * "role.super_admin") and tints with the per-role magenta accent (mirrors the
 * UserRole::color() ladder, SPEC §1.4). Theme- and locale-aware, dark/light.
 */
interface DashboardProps {
    username: string;
    role: string; // UserRole backing value, e.g. "super_admin"
    role_label_key: string; // e.g. "role.super_admin"
}

/** Mirrors App\Domain\Identity\Enums\UserRole::color() (SPEC §1.4). */
const ROLE_COLOR: Record<string, string> = {
    super_admin: '#9211CF',
    administrator: '#DD00FF',
    manager: '#E647FF',
    editor: '#A03CC7',
};

export default function Dashboard({ username, role, role_label_key }: DashboardProps) {
    const { t } = useLocale();
    const accent = ROLE_COLOR[role] ?? '#DD00FF';

    return (
        <>
            <Head title={t('admin.dashboard.title')} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <a
                            href="/admin/dashboard"
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
                            <LogoutButton />
                        </nav>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-6xl px-6 py-16">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-3xl font-extrabold tracking-tight">
                            {t('admin.dashboard.greeting')}, {username}
                        </h1>
                        <span
                            className="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold text-white"
                            style={{ backgroundColor: accent }}
                        >
                            {t(role_label_key)}
                        </span>
                    </div>

                    <p className="mt-4 max-w-2xl text-neutral-600 dark:text-slate-400">
                        {t('admin.dashboard.subtitle')}
                    </p>

                    <div className="mt-12 rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-border-dark dark:text-slate-400">
                        {t('admin.dashboard.shell_placeholder')}
                    </div>
                </main>
            </div>
        </>
    );
}
