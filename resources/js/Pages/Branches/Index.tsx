import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';

/**
 * Branch admin index (SPEC §3.2 ORG-02, §7.4). role:manager-gated. The list is
 * AUTO-SCOPED by the global OrganizationScope (no manual where) — a manager sees
 * only their own org's branches; an unconfined admin sees all. So the page never
 * filters: it just renders whatever the server returns.
 *
 * Props are snake_case, matching Admin\BranchController::index EXACTLY:
 *   { branches: Paginator<BranchRow> }
 * where BranchRow = { id, name, location, organization_name, representative_count }.
 *
 * Delete goes through ConfirmDialog → router.delete. ORG-02's has-representatives
 * guard (BranchHasRepresentativesException) is rendered server-side to a `branch`
 * field error (bootstrap/app.php) — shown here as a role="alert" banner, and the
 * row is never removed (the delete failed gracefully, never a 500). A successful
 * delete also application-cascades the branch's articles (server-side).
 */
interface BranchRow {
    id: number;
    name: string;
    location: string;
    organization_name: string;
    representative_count: number;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface BranchesIndexProps {
    branches: Paginator<BranchRow>;
}

const BASE_ROUTE = '/admin/branches';

export default function BranchesIndex({ branches }: BranchesIndexProps) {
    const { t } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [deleting, setDeleting] = useState<BranchRow | null>(null);
    const [processing, setProcessing] = useState(false);

    // ORG-02 has-representatives guard surfaces as a `branch` field error or flash.
    const blockMessage = pageErrors?.branch ?? flash?.error;

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

    const columns: DataColumn<BranchRow>[] = [
        {
            key: 'name',
            header: t('branches.col.name'),
            cell: (row) => <span className="font-medium">{row.name}</span>,
        },
        {
            key: 'location',
            header: t('branches.col.location'),
            cell: (row) => row.location,
        },
        {
            key: 'organization',
            header: t('branches.col.organization'),
            cell: (row) => row.organization_name,
        },
        {
            key: 'representative_count',
            header: t('branches.col.representatives'),
            align: 'right',
            cell: (row) => <span className="tabular-nums">{row.representative_count}</span>,
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
            title={t('branches.title')}
            subtitle={t('branches.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href={`${BASE_ROUTE}/create`}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('branches.action.new')}
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
                caption={t('branches.title')}
                columns={columns}
                rows={branches.data}
                rowKey={(row) => row.id}
                emptyMessage={t('branches.empty')}
            />

            <Pagination links={branches.links} />

            {deleting ? (
                <ConfirmDialog
                    title={t('branches.delete.title')}
                    message={t('branches.delete.confirm').replace('{name}', deleting.name)}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
