import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';
import ShiftBadge from '@/Components/organization/ShiftBadge';

/**
 * Representative admin index (SPEC §3.2 ORG-03, §7.4). role:manager-gated. The
 * list is AUTO-SCOPED by the global OrganizationScope (no manual where) — a
 * manager sees only their own org's representatives; an unconfined admin sees all.
 *
 * Props are snake_case, matching Admin\RepresentativeController::index EXACTLY:
 *   { representatives: Paginator<RepresentativeRow> }
 * where RepresentativeRow = { id, first_name, last_name, shift, shift_label_key,
 * is_coordinator, organization_name, branch_name }.
 *
 * The `shift` is the backing string of RepresentativeShift; `shift_label_key` is
 * its server-resolved i18n key (`representative_shift.<value>`) — rendered as a
 * magenta pill via ShiftBadge (TYPE-ONLY enum contract). Delete goes through
 * ConfirmDialog → router.delete.
 */
interface RepresentativeRow {
    id: number;
    first_name: string;
    last_name: string;
    shift: string;
    shift_label_key: string;
    is_coordinator: boolean;
    organization_name: string;
    branch_name: string;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface RepresentativesIndexProps {
    representatives: Paginator<RepresentativeRow>;
}

const BASE_ROUTE = '/admin/representatives';

export default function RepresentativesIndex({ representatives }: RepresentativesIndexProps) {
    const { t } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [deleting, setDeleting] = useState<RepresentativeRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const blockMessage = pageErrors?.representative ?? flash?.error;

    const fullName = (row: RepresentativeRow): string => `${row.first_name} ${row.last_name}`;

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

    const columns: DataColumn<RepresentativeRow>[] = [
        {
            key: 'name',
            header: t('representatives.col.name'),
            cell: (row) => <span className="font-medium">{fullName(row)}</span>,
        },
        {
            key: 'organization',
            header: t('representatives.col.organization'),
            cell: (row) => row.organization_name,
        },
        {
            key: 'branch',
            header: t('representatives.col.branch'),
            cell: (row) => row.branch_name,
        },
        {
            key: 'shift',
            header: t('representatives.col.shift'),
            cell: (row) => <ShiftBadge shift={row.shift} labelKey={row.shift_label_key} />,
        },
        {
            key: 'coordinator',
            header: t('representatives.col.coordinator'),
            cell: (row) => (
                <span
                    className={
                        row.is_coordinator
                            ? 'font-medium text-primary'
                            : 'text-neutral-400 dark:text-slate-500'
                    }
                >
                    {row.is_coordinator
                        ? t('representatives.coordinator_yes')
                        : t('representatives.coordinator_no')}
                </span>
            ),
        },
        {
            key: 'actions',
            header: t('content.col.actions'),
            align: 'right',
            cell: (row) => (
                <div className="flex justify-end gap-2">
                    <Link
                        href={`${BASE_ROUTE}/${row.id}/edit`}
                        className="inline-flex h-9 items-center rounded-md border border-neutral-300 px-3 text-sm font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                    >
                        {t('content.edit')}
                    </Link>
                    <button
                        type="button"
                        onClick={() => setDeleting(row)}
                        className="inline-flex h-9 items-center rounded-md border border-danger px-3 text-sm font-medium text-danger transition-colors hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:outline-none"
                    >
                        {t('content.delete')}
                    </button>
                </div>
            ),
        },
    ];

    return (
        <AdminShell
            title={t('representatives.title')}
            subtitle={t('representatives.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href={`${BASE_ROUTE}/create`}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('representatives.action.new')}
                </Link>
            }
        >
            {blockMessage ? (
                <p
                    role="alert"
                    className="mb-6 rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-medium text-danger"
                >
                    {blockMessage}
                </p>
            ) : null}

            <DataTable
                caption={t('representatives.title')}
                columns={columns}
                rows={representatives.data}
                rowKey={(row) => row.id}
                emptyMessage={t('representatives.empty')}
            />

            <Pagination links={representatives.links} />

            {deleting ? (
                <ConfirmDialog
                    title={t('representatives.delete.title')}
                    message={t('representatives.delete.confirm').replace(
                        '{name}',
                        fullName(deleting),
                    )}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
