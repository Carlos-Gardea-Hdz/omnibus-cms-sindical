import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DemoBanner from '@/Components/demo/DemoBanner';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';

/**
 * User admin index (CMS slice 008, AUTH-04). role:administrator-gated. The list is
 * org-confined server-side: an administrator sees only its own org's users, a
 * super_admin sees every user across orgs (the User model carries NO global
 * OrganizationScope — the controller drives confinement with an explicit where, so
 * the page just renders whatever rows it is given).
 *
 * Props are snake_case, matching Admin\UserController::index EXACTLY:
 *   {
 *     users: Array<{ id, username, name, email, role, role_label_key,
 *       organization_id, organization_name, is_demo, last_login_at, created_at }>,
 *     can: { create, assign_super_admin, manage_cross_org },
 *     filters: { organization_id }
 *   }
 * No password / remember_token ever rides in these props.
 *
 * Delete goes through ConfirmDialog → router.delete. The AUTH-04 guards
 * (cannot_delete_self, cannot_delete_last_super_admin) are enforced server-side and
 * surface as a graceful 302 + a flash/field error (rendered by AdminShell's banner);
 * the row is never removed on a blocked delete (never a 500 — users soft-delete).
 */
interface UserRow {
    id: number;
    username: string;
    name: string | null;
    email: string | null;
    role: string;
    role_label_key: string;
    organization_id: number | null;
    organization_name: string | null;
    is_demo: boolean;
    last_login_at: string | null;
    created_at: string;
}

interface UsersIndexProps {
    users: UserRow[];
    can: {
        create: boolean;
        assign_super_admin: boolean;
        manage_cross_org: boolean;
    };
    filters: { organization_id: number | null };
}

const BASE_ROUTE = '/admin/users';

const ROLE_BADGE: Record<string, string> = {
    super_admin:
        'bg-primary/15 text-primary-dark dark:text-primary-light border-primary/30',
    administrator: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-500/30',
    manager: 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/30',
    editor: 'bg-neutral-500/10 text-neutral-700 dark:text-slate-300 border-neutral-500/30',
};

export default function UsersIndex({ users, can, filters }: UsersIndexProps) {
    const { t, locale } = useLocale();
    const { errors: pageErrors } = usePage<PageProps>().props;
    const [deleting, setDeleting] = useState<UserRow | null>(null);
    const [processing, setProcessing] = useState(false);

    // AUTH-04 delete guards surface as a `user` field error or a flash error.
    const blockMessage = pageErrors?.user;

    const formatDate = (value: string | null): string =>
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

    const columns: DataColumn<UserRow>[] = [
        {
            key: 'username',
            header: t('admin.users.col.username'),
            cell: (row) => (
                <div className="flex flex-col">
                    <span className="flex items-center gap-2 font-medium">
                        {row.username}
                        {row.is_demo ? (
                            <span className="inline-flex rounded-full border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                {t('admin.users.badge.demo')}
                            </span>
                        ) : null}
                    </span>
                    {row.name ? (
                        <span className="text-xs text-neutral-400 dark:text-slate-500">
                            {row.name}
                        </span>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'email',
            header: t('admin.users.col.email'),
            cell: (row) =>
                row.email ?? (
                    <span className="text-neutral-400 dark:text-slate-500">—</span>
                ),
        },
        {
            key: 'role',
            header: t('admin.users.col.role'),
            cell: (row) => (
                <span
                    className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-semibold ${
                        ROLE_BADGE[row.role] ?? ROLE_BADGE.editor
                    }`}
                >
                    {t(row.role_label_key)}
                </span>
            ),
        },
        ...(can.manage_cross_org
            ? [
                  {
                      key: 'organization',
                      header: t('admin.users.col.organization'),
                      cell: (row: UserRow) =>
                          row.organization_name ?? (
                              <span className="text-neutral-400 dark:text-slate-500">—</span>
                          ),
                  } satisfies DataColumn<UserRow>,
              ]
            : []),
        {
            key: 'last_login_at',
            header: t('admin.users.col.last_login'),
            cell: (row) => (
                <span className="text-neutral-500 tabular-nums dark:text-slate-400">
                    {formatDate(row.last_login_at)}
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
        <>
            <DemoBanner />
            <AdminShell
                title={t('admin.users.title')}
                subtitle={t('admin.users.subtitle')}
                backHref="/admin/dashboard"
                backLabel={t('content.back_to_dashboard')}
                toolbar={
                    can.create ? (
                        <Link
                            href={`${BASE_ROUTE}/create`}
                            className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                        >
                            {t('admin.users.new')}
                        </Link>
                    ) : undefined
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

                {filters.organization_id !== null && can.manage_cross_org ? (
                    <p className="mb-4 text-sm text-neutral-500 dark:text-slate-400">
                        {t('admin.users.filtered_org')}
                    </p>
                ) : null}

                <DataTable
                    caption={t('admin.users.title')}
                    columns={columns}
                    rows={users}
                    rowKey={(row) => row.id}
                    emptyMessage={t('admin.users.empty')}
                />

                {deleting ? (
                    <ConfirmDialog
                        title={t('admin.users.delete.title')}
                        message={t('admin.users.confirm_delete').replace(
                            '{name}',
                            deleting.username,
                        )}
                        processing={processing}
                        onConfirm={confirmDelete}
                        onCancel={() => setDeleting(null)}
                    />
                ) : null}
            </AdminShell>
        </>
    );
}
