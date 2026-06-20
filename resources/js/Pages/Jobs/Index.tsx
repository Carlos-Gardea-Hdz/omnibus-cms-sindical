import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';

/**
 * Public job board index (slice 004, SPEC §3.4 JOB-03). UNCONFINED + ACTIVE-ONLY:
 * the controller lists jobs across ALL organizations but filtered to
 * JobStatus::Active (Public\JobController::index runs withoutGlobalScope and
 * where status = active). A draft / paused / closed job NEVER appears here — not
 * even to a logged-in editor whose session is org-confined (the public read path
 * is unconfined by design).
 *
 * Props are snake_case, matching Public\JobController::index EXACTLY:
 *   { jobs: Paginator<PublicJobRow> }
 * where PublicJobRow = { id, title, branch_name, schedule, salary_display,
 * created_at }. The wire NEVER carries `status` / `organization_id` /
 * `created_by` for a public job.
 *
 * Public chrome: branded header with the LanguageSwitcher + DarkModeToggle (no
 * auth controls), one #main, one <h1>. Themed magenta, dark/light aware,
 * bilingual via the locale hook.
 */
interface PublicJobRow {
    id: number;
    title: string;
    branch_name: string;
    schedule: string;
    salary_display: string | null;
    created_at: string;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface JobsIndexProps {
    jobs: Paginator<PublicJobRow>;
}

export default function PublicJobsIndex({ jobs }: JobsIndexProps) {
    const { t, locale } = useLocale();

    const formatDate = (value: string): string =>
        new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });

    return (
        <>
            <Head title={t('jobs.public.title')} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                        <Link
                            href="/"
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
                        </nav>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-4xl px-6 py-12">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {t('jobs.public.back_home')}
                    </Link>

                    <h1 className="mt-4 text-4xl font-extrabold tracking-tight">
                        {t('jobs.public.title')}
                    </h1>
                    <p className="mt-3 text-lg text-neutral-600 dark:text-slate-300">
                        {t('jobs.public.subtitle')}
                    </p>

                    {jobs.data.length === 0 ? (
                        <p className="mt-10 rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-border-dark dark:text-slate-400">
                            {t('jobs.public.empty')}
                        </p>
                    ) : (
                        <ul className="mt-8 grid gap-4">
                            {jobs.data.map((job) => (
                                <li key={job.id}>
                                    <Link
                                        href={`/jobs/${job.id}`}
                                        className="group flex flex-col gap-3 rounded-2xl border border-neutral-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-primary hover:shadow-xl hover:shadow-primary/10 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none sm:flex-row sm:items-center sm:justify-between dark:border-border-dark dark:bg-surface-dark"
                                    >
                                        <div className="flex flex-col gap-1">
                                            <h2 className="text-lg font-semibold transition group-hover:text-primary">
                                                {job.title}
                                            </h2>
                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-500 dark:text-slate-400">
                                                <span>{job.branch_name}</span>
                                                <span aria-hidden="true">·</span>
                                                <span>{job.schedule}</span>
                                                <span aria-hidden="true">·</span>
                                                <time
                                                    dateTime={job.created_at}
                                                    className="tabular-nums"
                                                >
                                                    {formatDate(job.created_at)}
                                                </time>
                                            </div>
                                        </div>
                                        {job.salary_display ? (
                                            <span className="shrink-0 rounded-full border border-primary/30 bg-primary-50 px-3 py-1 text-sm font-semibold text-primary-dark dark:bg-primary/10 dark:text-primary-light">
                                                {job.salary_display}
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    <Pagination links={jobs.links} />
                </main>
            </div>
        </>
    );
}
