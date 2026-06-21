import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';
import MemberStatusBadge, {
    type MemberStatusValue,
} from '@/Components/membership/MemberStatusBadge';

/**
 * Membership review admin index (slice 005, SPEC §3.6 / §7.3). role:manager-gated
 * (editor has NO member access — §10.2). The list is AUTO-SCOPED by the global
 * OrganizationScope (no manual where): a confined manager sees ONLY their own
 * org's members; an unconfined super_admin sees ALL, including org-less
 * (null-org) members. The cross-org write crown is the lifecycle 404 — a confined
 * manager approving/rejecting an org-B member gets a 404 via route-model-binding
 * under the scope (CONTRACT §0).
 *
 * Props are snake_case, matching Admin\MemberController::index EXACTLY:
 *   { members: Paginator<MemberRow>, statuses, filters: {status} }
 * where MemberRow = { id, full_name, municipality_name, status,
 * status_label_key, is_affiliated, created_at }.
 *
 * PII NEVER ON THE WIRE: curp / rfc are deliberately absent from MemberRow
 * (CONTRACT Decision E / §10.5) — this admin review list shows only the
 * non-sensitive identity fields. There is NO admin create / edit / delete for
 * members (§7.3 gives admin only index + approve + reject — CONTRACT §0); the
 * sole create path is the PUBLIC /membership/register form.
 *
 * The only mutations are the lifecycle transitions, offered ONLY on `pending`
 * rows (MemberStatus::Pending → Approved | Rejected; both targets terminal —
 * CONTRACT §2). Approved / rejected rows expose no action, so there are no
 * dead-end buttons; the server re-checks legality via Approve/RejectMemberAction
 * (an illegal edge → 302 + `status` session error, never a 500).
 */
interface MemberRow {
    id: number;
    full_name: string;
    municipality_name: string;
    status: string;
    status_label_key: string;
    is_affiliated: boolean;
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

interface MembersIndexProps {
    members: Paginator<MemberRow>;
    statuses: StatusOption[];
    filters: { status: string | null };
}

const BASE_ROUTE = '/admin/members';

const STATUS_FILTERS: (MemberStatusValue | 'all')[] = ['all', 'pending', 'approved', 'rejected'];

type LifecycleAction = 'approve' | 'reject';

export default function MembersIndex({ members, statuses, filters }: MembersIndexProps) {
    const { t, locale } = useLocale();
    const [pending, setPending] = useState<{ row: MemberRow; action: LifecycleAction } | null>(
        null,
    );
    const [processing, setProcessing] = useState(false);

    // `statuses` resolves the filter chip labels, falling back to the enum label
    // keys so the filter and the badges stay aligned.
    const labelKeyFor = (value: string): string =>
        statuses.find((status) => status.value === value)?.label_key ?? `member_status.${value}`;

    const formatDate = (value: string): string =>
        new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US');

    const applyFilter = (status: MemberStatusValue | 'all') => {
        router.get(BASE_ROUTE, status === 'all' ? {} : { status }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const confirmLifecycle = () => {
        if (!pending) {
            return;
        }
        const { row, action } = pending;
        setProcessing(true);
        router.post(
            `${BASE_ROUTE}/${row.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setPending(null);
                },
            },
        );
    };

    const columns: DataColumn<MemberRow>[] = [
        {
            key: 'full_name',
            header: t('members.col.name'),
            cell: (row) => <span className="font-medium">{row.full_name}</span>,
        },
        {
            key: 'municipality',
            header: t('members.col.municipality'),
            cell: (row) => row.municipality_name,
        },
        {
            key: 'is_affiliated',
            header: t('members.col.affiliated'),
            cell: (row) =>
                row.is_affiliated ? (
                    <span className="font-medium text-primary">{t('members.affiliated_yes')}</span>
                ) : (
                    <span className="text-neutral-400 dark:text-slate-500">
                        {t('members.affiliated_no')}
                    </span>
                ),
        },
        {
            key: 'status',
            header: t('members.col.status'),
            cell: (row) => (
                <MemberStatusBadge status={row.status} labelKey={row.status_label_key} />
            ),
        },
        {
            key: 'created_at',
            header: t('members.col.created_at'),
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
            cell: (row) =>
                row.status === 'pending' ? (
                    <div className="flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => setPending({ row, action: 'approve' })}
                            className="inline-flex h-9 items-center rounded-md border border-primary/40 px-3 text-sm font-medium text-primary transition-colors hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                        >
                            {t('members.action.approve')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setPending({ row, action: 'reject' })}
                            className="inline-flex h-9 items-center rounded-md border border-danger px-3 text-sm font-medium text-danger transition-colors hover:bg-danger/10 focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:outline-none"
                        >
                            {t('members.action.reject')}
                        </button>
                    </div>
                ) : (
                    <span className="text-neutral-400 dark:text-slate-500">—</span>
                ),
        },
    ];

    return (
        <AdminShell
            title={t('members.title')}
            subtitle={t('members.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
        >
            <div
                role="group"
                aria-label={t('members.filter.label')}
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
                            {status === 'all' ? t('members.filter.all') : t(labelKeyFor(status))}
                        </button>
                    );
                })}
            </div>

            <DataTable
                caption={t('members.title')}
                columns={columns}
                rows={members.data}
                rowKey={(row) => row.id}
                emptyMessage={t('members.empty')}
            />

            <Pagination links={members.links} />

            {pending ? (
                <ConfirmDialog
                    title={t(`members.action.${pending.action}`)}
                    message={t(`members.${pending.action}.confirm`).replace(
                        '{name}',
                        pending.row.full_name,
                    )}
                    confirmLabel={t(`members.action.${pending.action}`)}
                    processing={processing}
                    onConfirm={confirmLifecycle}
                    onCancel={() => setPending(null)}
                />
            ) : null}
        </AdminShell>
    );
}
