import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';

/**
 * Organization admin index (SPEC §3.2 ORG-01, §7.3). role:super_admin-gated —
 * organizations are the cross-org root, only the super admin manages them
 * (the OrganizationController is unscoped; this list shows every org).
 *
 * Props are snake_case, matching Admin\OrganizationController::index EXACTLY:
 *   { organizations: Paginator<OrganizationRow> }
 * where OrganizationRow = { id, name, slug, municipality_name, branch_count,
 * director_name | null, registered_at }.
 *
 * Delete goes through ConfirmDialog → router.delete; ORG-02's has-branches guard
 * (OrganizationInUseException) is rendered server-side to an `organization` field
 * error (bootstrap/app.php) — shown here as a role="alert" banner, and the row is
 * never removed (the delete failed gracefully, never a 500).
 */
interface OrganizationRow {
    id: number;
    name: string;
    slug: string;
    municipality_name: string;
    branch_count: number;
    director_name: string | null;
    registered_at: string;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface OrganizationsIndexProps {
    organizations: Paginator<OrganizationRow>;
}

const BASE_ROUTE = '/admin/organizations';

export default function OrganizationsIndex({ organizations }: OrganizationsIndexProps) {
    const { t, locale } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [deleting, setDeleting] = useState<OrganizationRow | null>(null);
    const [processing, setProcessing] = useState(false);

    // ORG-02 has-branches guard surfaces as an `organization` field error or flash.
    const blockMessage = pageErrors?.organization ?? flash?.error;

    const formatDate = (value: string): string =>
        value ? new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US') : '—';

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

    const columns: DataColumn<OrganizationRow>[] = [
        {
            key: 'name',
            header: t('organizations.col.name'),
            cell: (row) => (
                <div className="flex flex-col">
                    <span className="font-medium">{row.name}</span>
                    <span className="font-mono text-xs text-neutral-400 dark:text-slate-500">
                        {row.slug}
                    </span>
                </div>
            ),
        },
        {
            key: 'municipality',
            header: t('organizations.col.municipality'),
            cell: (row) => row.municipality_name,
        },
        {
            key: 'director',
            header: t('organizations.col.director'),
            cell: (row) =>
                row.director_name ?? (
                    <span className="text-neutral-400 dark:text-slate-500">
                        {t('organizations.director_none')}
                    </span>
                ),
        },
        {
            key: 'branch_count',
            header: t('organizations.col.branches'),
            align: 'right',
            cell: (row) => <span className="tabular-nums">{row.branch_count}</span>,
        },
        {
            key: 'registered_at',
            header: t('organizations.col.registered_at'),
            cell: (row) => (
                <span className="text-neutral-500 tabular-nums dark:text-slate-400">
                    {formatDate(row.registered_at)}
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
            title={t('organizations.title')}
            subtitle={t('organizations.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href={`${BASE_ROUTE}/create`}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('organizations.action.new')}
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
                caption={t('organizations.title')}
                columns={columns}
                rows={organizations.data}
                rowKey={(row) => row.id}
                emptyMessage={t('organizations.empty')}
            />

            <Pagination links={organizations.links} />

            {deleting ? (
                <ConfirmDialog
                    title={t('organizations.delete.title')}
                    message={t('organizations.delete.confirm').replace('{name}', deleting.name)}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
