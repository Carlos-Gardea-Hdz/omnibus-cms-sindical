import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import Pagination, { type PaginatorLink } from '@/Components/content/Pagination';

/**
 * Contact-message admin inbox (slice 006, SPEC §8.1 / §6.3.11). role:manager-gated
 * (editor has NO contact access — §10.2 lists Contacts as `—` for editor). The
 * list is AUTO-SCOPED by the global OrganizationScope (no manual where): a confined
 * manager sees ONLY their own org's messages; an unconfined super_admin sees ALL.
 *
 * This page is STRICTLY READ-ONLY — there are NO action buttons. `contact_messages`
 * has no status/moderation/spam column and no `deleted_at` (CONTRACT §0): a row is
 * its own terminal state and a permanent audit record, so there is nothing to
 * approve / reject / delete / mark-read. The only job of this screen is to read the
 * inbox; replying happens out of band (the captured email/phone are the point).
 *
 * Props are snake_case, matching Admin\ContactController::index EXACTLY:
 *   { messages: Paginator<ContactRow>, filters: { organization_id } }
 * where ContactRow = { id, full_name, email, phone, branch_name, message,
 * created_at }. Email + phone ARE shown — this is an org-scoped inbox whose whole
 * purpose is replying to the sender, not a public leak (CONTRACT §6).
 *
 * SECURITY: `message` is plain TEXT (not TipTap JSONB — SanitizesContent does NOT
 * apply, CONTRACT §0). It is rendered as ESCAPED PLAIN TEXT via a React text node
 * (`{row.message}`) — NEVER dangerouslySetInnerHTML — so a submitter cannot inject
 * markup into the admin view (stored-XSS defence by default React escaping).
 */
interface ContactRow {
    id: number;
    full_name: string;
    email: string;
    phone: string;
    branch_name: string;
    message: string;
    created_at: string;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface ContactsIndexProps {
    messages: Paginator<ContactRow>;
    filters: { organization_id: number | null };
}

export default function ContactsIndex({ messages }: ContactsIndexProps) {
    const { t, locale } = useLocale();

    const formatDate = (value: string): string =>
        new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US');

    const columns: DataColumn<ContactRow>[] = [
        {
            key: 'full_name',
            header: t('contact.col.name'),
            cell: (row) => <span className="font-medium">{row.full_name}</span>,
        },
        {
            key: 'email',
            header: t('contact.col.email'),
            cell: (row) => (
                <a
                    href={`mailto:${row.email}`}
                    className="text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                >
                    {row.email}
                </a>
            ),
        },
        {
            key: 'phone',
            header: t('contact.col.phone'),
            cell: (row) => <span className="tabular-nums">{row.phone}</span>,
        },
        {
            key: 'branch',
            header: t('contact.col.branch'),
            cell: (row) => row.branch_name,
        },
        {
            key: 'message',
            header: t('contact.col.message'),
            cellClassName: 'max-w-md',
            // Plain-text TEXT column. React escapes this text node by default —
            // NEVER dangerouslySetInnerHTML (CONTRACT §0 / §7). `whitespace-pre-line`
            // preserves the submitter's line breaks without interpreting markup.
            cell: (row) => (
                <span className="block whitespace-pre-line break-words text-neutral-700 dark:text-slate-300">
                    {row.message}
                </span>
            ),
        },
        {
            key: 'created_at',
            header: t('contact.col.created_at'),
            cell: (row) => (
                <span className="text-neutral-500 tabular-nums dark:text-slate-400">
                    {formatDate(row.created_at)}
                </span>
            ),
        },
    ];

    return (
        <AdminShell
            title={t('contact.admin.title')}
            subtitle={t('contact.admin.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
        >
            <DataTable
                caption={t('contact.admin.title')}
                columns={columns}
                rows={messages.data}
                rowKey={(row) => row.id}
                emptyMessage={t('contact.admin.empty')}
            />

            <Pagination links={messages.links} />
        </AdminShell>
    );
}
