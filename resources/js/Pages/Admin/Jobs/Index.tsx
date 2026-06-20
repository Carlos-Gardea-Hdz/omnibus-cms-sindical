import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';
import JobStatusBadge, { type JobStatusValue } from '@/Components/jobs/JobStatusBadge';

/**
 * Job posting admin index (slice 004, SPEC §3.4 / §7.2). role:editor-gated for
 * list / CRUD / lifecycle (JOB-02 is editor-scoped — unlike Article publishing).
 * The list is AUTO-SCOPED by the global OrganizationScope (no manual where) — a
 * confined manager/editor sees only their own org's jobs; an unconfined admin
 * sees all.
 *
 * Props are snake_case, matching Admin\JobController::index EXACTLY:
 *   { jobs: Paginator<JobRow>, branch_options, statuses, filters: {status} }
 * where JobRow = { id, title, branch_name, status, status_label_key,
 * salary_display, created_at }.
 *
 * Status transitions mirror App\Domain\Jobs\Enums\JobStatus::allowedTransitions()
 * (the enum is the SSOT) so the UI only offers legal moves; the server re-checks
 * via ToggleJobStatusAction (an illegal toggle → 302 + `status` session error,
 * never a 500). The toggle POSTs { status: target } to /admin/jobs/{id}/status.
 */
interface JobRow {
    id: number;
    title: string;
    branch_name: string;
    status: string;
    status_label_key: string;
    salary_display: string | null;
    created_at: string;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface StatusOption {
    value: string;
    label_key: string;
}

interface JobsIndexProps {
    jobs: Paginator<JobRow>;
    branch_options: { id: number; name: string }[];
    statuses: StatusOption[];
    filters: { status: string | null };
}

const BASE_ROUTE = '/admin/jobs';

/**
 * The legal next-states per current status, mirroring
 * JobStatus::allowedTransitions() (the SSOT). The UI offers only these moves so
 * there are no dead-end buttons; the server re-checks legality and a closed job
 * (terminal) exposes none.
 */
const ALLOWED_TRANSITIONS: Record<JobStatusValue, JobStatusValue[]> = {
    draft: ['active', 'closed'],
    active: ['paused', 'closed'],
    paused: ['active', 'closed'],
    closed: [],
};

const STATUS_FILTERS: (JobStatusValue | 'all')[] = ['all', 'draft', 'active', 'paused', 'closed'];

export default function JobsIndex({ jobs, statuses, filters }: JobsIndexProps) {
    const { t, locale } = useLocale();
    const [pending, setPending] = useState<{ row: JobRow; target: JobStatusValue } | null>(null);
    const [deleting, setDeleting] = useState<JobRow | null>(null);
    const [processing, setProcessing] = useState(false);

    // `statuses` is consumed by the filter chips' label resolution; the labels
    // also fall back to the enum label keys so the filter and badges stay aligned.
    const labelKeyFor = (value: string): string =>
        statuses.find((status) => status.value === value)?.label_key ?? `job_status.${value}`;

    const formatDate = (value: string): string =>
        new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US');

    const applyFilter = (status: JobStatusValue | 'all') => {
        router.get(BASE_ROUTE, status === 'all' ? {} : { status }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const confirmTransition = () => {
        if (!pending) {
            return;
        }
        const { row, target } = pending;
        setProcessing(true);
        router.post(
            `${BASE_ROUTE}/${row.id}/status`,
            { status: target },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setPending(null);
                },
            },
        );
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }
        setProcessing(true);
        router.delete(`${BASE_ROUTE}/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });
    };

    const columns: DataColumn<JobRow>[] = [
        {
            key: 'title',
            header: t('jobs.col.title'),
            cell: (row) => <span className="font-medium">{row.title}</span>,
        },
        {
            key: 'branch',
            header: t('jobs.col.branch'),
            cell: (row) => row.branch_name,
        },
        {
            key: 'salary',
            header: t('jobs.col.salary'),
            cell: (row) =>
                row.salary_display ? (
                    <span className="font-medium text-primary">{row.salary_display}</span>
                ) : (
                    <span className="text-neutral-400 dark:text-slate-500">—</span>
                ),
        },
        {
            key: 'status',
            header: t('jobs.col.status'),
            cell: (row) => <JobStatusBadge status={row.status} labelKey={row.status_label_key} />,
        },
        {
            key: 'created_at',
            header: t('jobs.col.created_at'),
            cell: (row) => (
                <span className="text-neutral-500 tabular-nums dark:text-slate-400">
                    {formatDate(row.created_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: t('content.col.actions'),
            align: 'right',
            cell: (row) => {
                const targets = ALLOWED_TRANSITIONS[row.status as JobStatusValue] ?? [];
                return (
                    <div className="flex flex-wrap justify-end gap-2">
                        <Link
                            href={`${BASE_ROUTE}/${row.id}/edit`}
                            className="inline-flex h-9 items-center rounded-md border border-neutral-300 px-3 text-sm font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                        >
                            {t('content.edit')}
                        </Link>
                        {targets.map((target) => (
                            <button
                                key={target}
                                type="button"
                                onClick={() => setPending({ row, target })}
                                className="inline-flex h-9 items-center rounded-md border border-primary/40 px-3 text-sm font-medium text-primary transition-colors hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            >
                                {t(`jobs.action.to_${target}`)}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setDeleting(row)}
                            className="inline-flex h-9 items-center rounded-md border border-danger px-3 text-sm font-medium text-danger transition-colors hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:outline-none"
                        >
                            {t('content.delete')}
                        </button>
                    </div>
                );
            },
        },
    ];

    return (
        <AdminShell
            title={t('jobs.title')}
            subtitle={t('jobs.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href={`${BASE_ROUTE}/create`}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('jobs.action.new')}
                </Link>
            }
        >
            <div
                role="group"
                aria-label={t('jobs.filter.label')}
                className="mb-6 flex flex-wrap gap-2"
            >
                {STATUS_FILTERS.map((status) => {
                    const active = (filters.status ?? 'all') === status;
                    return (
                        <button
                            key={status}
                            type="button"
                            onClick={() => applyFilter(status)}
                            aria-pressed={active}
                            className={`inline-flex h-9 items-center rounded-full px-4 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none ${
                                active
                                    ? 'bg-primary text-white'
                                    : 'border border-neutral-300 text-neutral-700 hover:border-primary hover:text-primary dark:border-border-dark dark:text-slate-300'
                            }`}
                        >
                            {status === 'all' ? t('jobs.filter.all') : t(labelKeyFor(status))}
                        </button>
                    );
                })}
            </div>

            <DataTable
                caption={t('jobs.title')}
                columns={columns}
                rows={jobs.data}
                rowKey={(row) => row.id}
                emptyMessage={t('jobs.empty')}
            />

            <Pagination links={jobs.links} />

            {pending ? (
                <ConfirmDialog
                    title={t(`jobs.action.to_${pending.target}`)}
                    message={t('jobs.transition.confirm')
                        .replace('{title}', pending.row.title)
                        .replace('{status}', t(`job_status.${pending.target}`))}
                    confirmLabel={t(`jobs.action.to_${pending.target}`)}
                    processing={processing}
                    onConfirm={confirmTransition}
                    onCancel={() => setPending(null)}
                />
            ) : null}

            {deleting ? (
                <ConfirmDialog
                    title={t('jobs.delete.title')}
                    message={t('jobs.delete.confirm').replace('{title}', deleting.title)}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
