import { Head, useForm } from '@inertiajs/react';
import { useId } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import TextField from '@/Components/form/TextField';

/**
 * Login page (SPEC §3.1 AUTH-01, §7.1) — username + password + "remember me".
 *
 * Login key is `username` (NOT email — this is the deliberate CMS divergence from
 * the UNIGES reference). The form is typed by the generated ambient type
 * `App.Domain.Identity.Data.LoginData` — the auto-emitted TS mirror of the Spatie
 * Data DTO `App\Domain\Identity\Data\LoginData`, which owns validation server-side.
 * It is a pure ambient declaration (no import, no runtime value — the transformer
 * emits `declare namespace App` into `@/types/generated`), so the form shape can
 * never drift from the backend contract: `{ username, password, remember }`,
 * snake_case throughout, matching the POST payload the LoginController expects.
 *
 * Theme- and locale-aware: renders the shared LanguageSwitcher + DarkModeToggle,
 * and every string flows through `useLocale().t`. Per AUTH-01 the server returns
 * the SAME generic message for unknown-username and wrong-password (no user
 * enumeration), flashed on the `username` field and surfaced inline + announced
 * via the field's `role="alert"` error node (WCAG 2.2 — error identification).
 */
type LoginForm = App.Domain.Identity.Data.LoginData;

export default function Login() {
    const { t } = useLocale();

    const { data, setData, post, processing, errors } = useForm<LoginForm>({
        username: '',
        password: '',
        remember: false,
    });

    const rememberId = useId();

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/login', { preserveScroll: true, onFinish: () => setData('password', '') });
    };

    return (
        <>
            <Head title={t('auth.login.title')} />
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
                    className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-6 py-12"
                >
                    <div className="pointer-events-none absolute top-24 left-1/2 -z-10 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-primary/15 blur-3xl dark:bg-primary/10" />

                    <h1 className="text-2xl font-bold tracking-tight">{t('auth.login.title')}</h1>
                    <p className="mt-2 text-neutral-600 dark:text-slate-400">
                        {t('auth.login.subtitle')}
                    </p>

                    <form onSubmit={submit} noValidate className="mt-8 flex flex-col gap-5">
                        <TextField
                            label={t('auth.login.field.username')}
                            name="username"
                            value={data.username}
                            onChange={(value) => setData('username', value)}
                            error={errors.username}
                            required
                            type="text"
                            autoComplete="username"
                            autoCapitalize="none"
                            spellCheck={false}
                            maxLength={60}
                            autoFocus
                        />

                        <TextField
                            label={t('auth.login.field.password')}
                            name="password"
                            value={data.password}
                            onChange={(value) => setData('password', value)}
                            error={errors.password}
                            required
                            type="password"
                            autoComplete="current-password"
                        />

                        <label
                            htmlFor={rememberId}
                            className="flex cursor-pointer items-center gap-2 text-sm text-neutral-700 dark:text-slate-300"
                        >
                            <input
                                id={rememberId}
                                type="checkbox"
                                checked={data.remember}
                                onChange={(event) => setData('remember', event.target.checked)}
                                className="h-4 w-4 rounded border-neutral-300 text-primary accent-primary focus-visible:ring-2 focus-visible:ring-primary/40 dark:border-border-dark dark:bg-surface-dark"
                            />
                            {t('auth.login.remember')}
                        </label>

                        <div className="flex items-center gap-3">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex h-11 items-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                            >
                                {t('auth.login.submit')}
                            </button>
                            {processing ? (
                                <span
                                    className="text-sm text-neutral-500 dark:text-slate-400"
                                    role="status"
                                >
                                    {t('auth.login.signing_in')}
                                </span>
                            ) : null}
                        </div>
                    </form>
                </main>
            </div>
        </>
    );
}
