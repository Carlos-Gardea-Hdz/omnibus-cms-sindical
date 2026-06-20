import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';

/**
 * Director admin index (SPEC §3.2 ORG-04, §7.3). role:administrator-gated.
 * Directors are NOT org-scoped at the list level here (administrators are
 * unconfined); each director belongs to exactly one organization (ORG-04).
 *
 * Props are snake_case, matching Admin\DirectorController::index EXACTLY:
 *   { directors: Paginator<DirectorRow> }
 * where DirectorRow = { id, first_name, last_name, organization_name,
 * photo_url | null }.
 *
 * Delete goes through ConfirmDialog → router.delete; the delete nulls out the
 * org's director_id server-side (DeleteDirectorAction). Any server-side guard
 * surfaces as a `director` field error / flash banner; the row is never removed
 * on failure.
 */
interface DirectorRow {
    id: number;
    first_name: string;
    last_name: string;
    organization_name: string;
    photo_url: string | null;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface DirectorsIndexProps {
    directors: Paginator<DirectorRow>;
}

const BASE_ROUTE = '/admin/directors';

export default function DirectorsIndex({ directors }: DirectorsIndexProps) {
    const { t } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [deleting, setDeleting] = useState<DirectorRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const blockMessage = pageErrors?.director ?? flash?.error;

    const fullName = (row: DirectorRow): string => `${row.first_name} ${row.last_name}`;

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

    const columns: DataColumn<DirectorRow>[] = [
        {
            key: 'photo',
            header: t('directors.col.photo'),
            cell: (row) =>
                row.photo_url ? (
                    <img
                        src={row.photo_url}
                        alt={fullName(row)}
                        className="h-10 w-10 rounded-full object-cover"
                    />
                ) : (
                    <span
                        aria-hidden="true"
                        className="grid h-10 w-10 place-items-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-400 dark:bg-surface-dark dark:text-slate-500"
                    >
                        {row.first_name.charAt(0)}
                        {row.last_name.charAt(0)}
                    </span>
                ),
        },
        {
            key: 'name',
            header: t('directors.col.name'),
            cell: (row) => <span className="font-medium">{fullName(row)}</span>,
        },
        {
            key: 'organization',
            header: t('directors.col.organization'),
            cell: (row) => row.organization_name,
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
            title={t('directors.title')}
            subtitle={t('directors.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href={`${BASE_ROUTE}/create`}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('directors.action.new')}
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
                caption={t('directors.title')}
                columns={columns}
                rows={directors.data}
                rowKey={(row) => row.id}
                emptyMessage={t('directors.empty')}
            />

            <Pagination links={directors.links} />

            {deleting ? (
                <ConfirmDialog
                    title={t('directors.delete.title')}
                    message={t('directors.delete.confirm').replace('{name}', fullName(deleting))}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
