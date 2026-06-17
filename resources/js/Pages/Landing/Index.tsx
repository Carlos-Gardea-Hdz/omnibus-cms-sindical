import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

interface ArticleCard {
    id: string;
    title: string;
    subtitle?: string | null;
    slug: string;
    published_at?: string | null;
}

interface JobCard {
    id: string;
    title: string;
    salary_display?: string | null;
}

interface LandingProps {
    latestArticles?: ArticleCard[];
    latestJobs?: JobCard[];
}

type Locale = 'es' | 'en';

const copy = {
    es: {
        nav_news: 'Noticias',
        nav_jobs: 'Empleos',
        nav_login: 'Entrar',
        hero_tag: 'Plataforma Empresarial',
        hero_title: 'Gestión corporativa multi-sucursal, reimaginada.',
        hero_sub: 'Búsqueda full-text, RBAC de 4 niveles y analítica segmentada. Una reconstrucción Laravel 12 de un sistema en producción real.',
        hero_cta: 'Explorar demo',
        hero_cta2: 'Ver noticias',
        news_title: 'Últimas noticias',
        news_empty: 'Aún no hay noticias publicadas. Pronto verás aquí lo más reciente.',
        jobs_title: 'Vacantes activas',
        jobs_empty: 'No hay vacantes activas por ahora.',
        feat_title: 'Construido sobre estándares de producción',
        footer: 'Reconstrucción Laravel 12 · Demo sin fricción para reclutadores.',
    },
    en: {
        nav_news: 'News',
        nav_jobs: 'Jobs',
        nav_login: 'Sign in',
        hero_tag: 'Corporate Platform',
        hero_title: 'Multi-branch corporate management, reimagined.',
        hero_sub: 'Full-text search, 4-level RBAC and segmented analytics. A Laravel 12 rebuild of a real production system.',
        hero_cta: 'Explore demo',
        hero_cta2: 'View news',
        news_title: 'Latest news',
        news_empty: 'No published news yet. The latest articles will appear here soon.',
        jobs_title: 'Active openings',
        jobs_empty: 'No active openings right now.',
        feat_title: 'Built on production-grade standards',
        footer: 'Laravel 12 rebuild · Frictionless demo for recruiters.',
    },
} satisfies Record<Locale, Record<string, string>>;

const features = [
    { es: 'DDD Lite + Action Pattern', en: 'DDD Lite + Action Pattern' },
    { es: 'PostgreSQL 18 · Meilisearch', en: 'PostgreSQL 18 · Meilisearch' },
    { es: 'React 19 · Inertia · Tailwind v4', en: 'React 19 · Inertia · Tailwind v4' },
    { es: 'Modo claro/oscuro · ES/EN', en: 'Light/dark mode · ES/EN' },
];

function useTheme() {
    const [dark, setDark] = useState(false);
    useEffect(() => {
        setDark(document.documentElement.classList.contains('dark'));
    }, []);
    const toggle = useCallback(() => {
        setDark((d) => {
            const next = !d;
            document.documentElement.classList.toggle('dark', next);
            try {
                localStorage.setItem('theme', next ? 'dark' : 'light');
            } catch {
                /* ignore */
            }
            return next;
        });
    }, []);
    return { dark, toggle };
}

export default function Landing({ latestArticles = [], latestJobs = [] }: LandingProps) {
    const [locale, setLocale] = useState<Locale>('es');
    const { dark, toggle } = useTheme();
    const t = copy[locale];

    return (
        <>
            <Head title={t.hero_tag} />

            <div className="min-h-screen bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                {/* Header */}
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <a href="/" className="flex items-center gap-2 text-lg font-extrabold tracking-tight">
                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary-dark text-white">C</span>
                            <span className="text-gradient-primary">Corporate CMS</span>
                        </a>
                        <nav className="hidden items-center gap-7 text-sm font-medium text-neutral-600 sm:flex dark:text-slate-300">
                            <a href="#news" className="transition hover:text-primary">{t.nav_news}</a>
                            <a href="#jobs" className="transition hover:text-primary">{t.nav_jobs}</a>
                        </nav>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={() => setLocale((l) => (l === 'es' ? 'en' : 'es'))}
                                className="rounded-lg border border-neutral-200 px-2.5 py-1.5 text-xs font-semibold uppercase transition hover:border-primary hover:text-primary dark:border-border-dark"
                                aria-label="Toggle language"
                            >
                                {locale === 'es' ? 'EN' : 'ES'}
                            </button>
                            <button
                                onClick={toggle}
                                className="grid h-9 w-9 place-items-center rounded-lg border border-neutral-200 transition hover:border-primary hover:text-primary dark:border-border-dark"
                                aria-label="Toggle theme"
                            >
                                {dark ? '☀' : '☾'}
                            </button>
                            <a
                                href="/login"
                                className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-dark"
                            >
                                {t.nav_login}
                            </a>
                        </div>
                    </div>
                </header>

                {/* Hero */}
                <section className="relative overflow-hidden">
                    <div className="pointer-events-none absolute -top-32 left-1/2 h-96 w-[48rem] -translate-x-1/2 rounded-full bg-primary/20 blur-3xl dark:bg-primary/15" />
                    <div className="relative mx-auto max-w-6xl px-6 py-24 text-center">
                        <span className="inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary-50 px-4 py-1.5 text-sm font-semibold text-primary-dark dark:bg-primary/10 dark:text-primary-light">
                            {t.hero_tag}
                        </span>
                        <h1 className="mx-auto mt-6 max-w-3xl text-4xl font-extrabold tracking-tight sm:text-6xl">
                            {t.hero_title}
                        </h1>
                        <p className="mx-auto mt-6 max-w-2xl text-lg text-neutral-600 dark:text-slate-300">
                            {t.hero_sub}
                        </p>
                        <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
                            <a href="/login" className="rounded-xl bg-primary px-7 py-3 font-semibold text-white shadow-lg shadow-primary/25 transition hover:bg-primary-dark">
                                {t.hero_cta}
                            </a>
                            <a href="#news" className="rounded-xl border border-neutral-300 px-7 py-3 font-semibold transition hover:border-primary hover:text-primary dark:border-border-dark">
                                {t.hero_cta2}
                            </a>
                        </div>

                        <div className="mx-auto mt-16 grid max-w-4xl grid-cols-2 gap-4 sm:grid-cols-4">
                            {features.map((f) => (
                                <div
                                    key={f.en}
                                    className="rounded-2xl border border-neutral-200 bg-white/60 p-4 text-sm font-medium text-neutral-700 backdrop-blur dark:border-border-dark dark:bg-surface-dark/50 dark:text-slate-200"
                                >
                                    {f[locale]}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* News */}
                <section id="news" className="mx-auto max-w-6xl px-6 py-16">
                    <h2 className="text-2xl font-bold tracking-tight">{t.news_title}</h2>
                    {latestArticles.length === 0 ? (
                        <p className="mt-6 rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-border-dark dark:text-slate-400">
                            {t.news_empty}
                        </p>
                    ) : (
                        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {latestArticles.map((a) => (
                                <a
                                    key={a.id}
                                    href={`/articles/${a.slug}`}
                                    className="group rounded-2xl border border-neutral-200 bg-white p-6 transition hover:-translate-y-1 hover:border-primary hover:shadow-xl hover:shadow-primary/10 dark:border-border-dark dark:bg-surface-dark"
                                >
                                    <h3 className="font-semibold transition group-hover:text-primary">{a.title}</h3>
                                    {a.subtitle && <p className="mt-2 text-sm text-neutral-500 dark:text-slate-400">{a.subtitle}</p>}
                                </a>
                            ))}
                        </div>
                    )}
                </section>

                {/* Jobs */}
                <section id="jobs" className="mx-auto max-w-6xl px-6 pb-24">
                    <h2 className="text-2xl font-bold tracking-tight">{t.jobs_title}</h2>
                    {latestJobs.length === 0 ? (
                        <p className="mt-6 rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-border-dark dark:text-slate-400">
                            {t.jobs_empty}
                        </p>
                    ) : (
                        <div className="mt-8 grid gap-4 sm:grid-cols-2">
                            {latestJobs.map((j) => (
                                <div key={j.id} className="flex items-center justify-between rounded-xl border border-neutral-200 bg-white p-5 dark:border-border-dark dark:bg-surface-dark">
                                    <span className="font-medium">{j.title}</span>
                                    {j.salary_display && <span className="text-sm font-semibold text-primary">{j.salary_display}</span>}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <footer className="border-t border-neutral-200 py-10 text-center text-sm text-neutral-500 dark:border-border-dark dark:text-slate-400">
                    {t.footer}
                </footer>
            </div>
        </>
    );
}
