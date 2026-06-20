import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';

/**
 * Public job posting detail page (slice 004, SPEC §3.4). UNCONFINED + ACTIVE-ONLY:
 * Public\JobController::show resolves the job withoutGlobalScope but
 * where status = active, so a draft / paused / closed job of ANY org 404s — this
 * page only ever renders an active posting.
 *
 * Props are snake_case, matching Public\JobController::show EXACTLY:
 *   { job: { title, description, schedule, contact_info, branch_name,
 *     salary_display, created_at } }.
 * The wire NEVER carries `status` / `organization_id` / `created_by` — the
 * controller projects only the public-safe columns.
 *
 * The `description` is PLAIN TEXT (CONTRACT Decision A — not rich/TipTap), so it
 * renders inside a `whitespace-pre-line` block: line breaks are honoured and the
 * value is set as a React text child (NOT dangerouslySetInnerHTML), so a stored
 * payload cannot become live markup.
 *
 * Public chrome: branded header with the LanguageSwitcher + DarkModeToggle (no
 * auth controls), one #main, one <h1>. Themed magenta, dark/light aware.
 */
interface JobShowModel {
    title: string;
    description: string;
    schedule: string;
    contact_info: string;
    branch_name: string;
    salary_display: string | null;
    created_at: string;
}

interface ShowProps {
    job: JobShowModel;
}

export default function PublicJobsShow({ job }: ShowProps) {
    const { t, locale } = useLocale();

    const postedLabel = new Date(job.created_at).toLocaleDateString(
        locale === 'es' ? 'es-MX' : 'en-US',
        { year: 'numeric', month: 'long', day: 'numeric' },
    );

    return (
        <>
            <Head title={job.title} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
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

                <main id="main" className="mx-auto max-w-3xl px-6 py-12">
                    <Link
                        href="/jobs"
                        className="inline-flex items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {t('jobs.public.back_to_board')}
                    </Link>

                    <article className="mt-6">
                        <span className="inline-flex items-center rounded-full border border-primary/30 bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-dark dark:bg-primary/10 dark:text-primary-light">
                            {job.branch_name}
                        </span>

                        <h1 className="mt-4 text-4xl font-extrabold tracking-tight">{job.title}</h1>

                        <div className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-neutral-500 dark:text-slate-400">
                            <span>{job.schedule}</span>
                            <span aria-hidden="true">·</span>
                            <time dateTime={job.created_at} className="tabular-nums">
                                {postedLabel}
                            </time>
                        </div>

                        {job.salary_display ? (
                            <p className="mt-6 inline-flex items-center rounded-full bg-primary px-4 py-1.5 text-sm font-semibold text-white">
                                {job.salary_display}
                            </p>
                        ) : null}

                        <div className="mt-8 text-base leading-relaxed whitespace-pre-line text-neutral-800 dark:text-slate-200">
                            {job.description}
                        </div>

                        <div className="mt-10 rounded-2xl border border-neutral-200 bg-neutral-50 p-6 dark:border-border-dark dark:bg-surface-dark">
                            <h2 className="text-sm font-semibold text-neutral-500 dark:text-slate-400">
                                {t('jobs.public.how_to_apply')}
                            </h2>
                            <p className="mt-2 text-base font-medium whitespace-pre-line text-neutral-900 dark:text-slate-100">
                                {job.contact_info}
                            </p>
                        </div>
                    </article>
                </main>
            </div>
        </>
    );
}
