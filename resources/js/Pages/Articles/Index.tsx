import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import StatusBadge, { type ArticleStatusValue } from '@/Components/content/StatusBadge';

/**
 * Article admin index (SPEC §3.3, §7.2). role:editor-gated for list/CRUD;
 * publish / archive controls POST to manager-gated routes (the server is
 * authoritative — an editor who somehow POSTs gets 403; the controls are shown
 * for affordance and the action simply fails for under-privileged users).
 *
 * Props are snake_case, matching Admin\ArticleController::index EXACTLY:
 *   { articles: Paginator<ArticleRow>, categories: {id,name}[], filters:{status} }
 * where ArticleRow = { id, title, slug, status, status_label_key, category_name,
 * author_name, published_at }.
 *
 * Status transitions mirror App\Domain\Content\Enums\ArticleStatus::allowedTransitions()
 * (the enum is the SSOT) so the UI only offers legal moves; the server re-checks.
 */
interface ArticleRow {
    id: number;
    title: string;
    slug: string;
    status: string;
    status_label_key: string;
    category_name: string;
    author_name: string;
    published_at: string | null;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginator<T> {
    data: T[];
    links: PaginatorLink[];
    meta?: { from?: number | null; to?: number | null; total?: number };
}

interface ArticlesIndexProps {
    articles: Paginator<ArticleRow>;
    categories: { id: number; name: string }[];
    filters: { status: string | null };
}

/**
 * The lifecycle actions the backend actually exposes this slice (manager-gated).
 * Two state-changing routes exist:
 *   - POST .../publish  → PublishArticleAction  → status becomes Published
 *                         (legal from draft, and from archived as a republish).
 *   - POST .../archive  → UnpublishArticleAction → status returns to Draft
 *                         (legal only from published — the "unpublish" edge).
 * The ArticleStatus enum remains the server-side SSOT; the UI only surfaces the
 * moves the controller can perform, so there are no dead-end buttons. (A true
 * →archived edge + republish-from-archived UI lands when the backend wires an
 * archive Action — deferred.)
 */
type LifecycleAction = 'publish' | 'unpublish';

const ACTIONS_FOR_STATUS: Record<ArticleStatusValue, LifecycleAction[]> = {
    draft: ['publish'],
    published: ['unpublish'],
    archived: ['publish'], // republish — only reachable once archiving exists
};

const STATUS_FILTERS: (ArticleStatusValue | 'all')[] = ['all', 'draft', 'published', 'archived'];

export default function ArticlesIndex({ articles, filters }: ArticlesIndexProps) {
    const { t, locale } = useLocale();
    // Flash banners (transition / delete results) render inside AdminShell.
    const [pending, setPending] = useState<{ row: ArticleRow; action: LifecycleAction } | null>(
        null,
    );
    const [deleting, setDeleting] = useState<ArticleRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const formatDate = (value: string | null): string =>
        value ? new Date(value).toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US') : '—';

    const applyFilter = (status: ArticleStatusValue | 'all') => {
        router.get('/admin/articles', status === 'all' ? {} : { status }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    // The route for each lifecycle action — publish (→published) and unpublish
    // (→draft, on the controller's `archive` route). The server re-checks legality.
    const endpointFor = (row: ArticleRow, action: LifecycleAction): string =>
        action === 'publish'
            ? `/admin/articles/${row.id}/publish`
            : `/admin/articles/${row.id}/archive`;

    const actionLabel = (action: LifecycleAction, from: string): string => {
        if (action === 'publish') {
            return from === 'archived'
                ? t('articles.action.republish')
                : t('articles.action.publish');
        }
        return t('articles.action.unpublish');
    };

    const actionTargetLabel = (action: LifecycleAction): string =>
        action === 'publish' ? t('article_status.published') : t('article_status.draft');

    const confirmTransition = () => {
        if (!pending) {
            return;
        }
        const { row, action } = pending;
        setProcessing(true);
        router.post(
            endpointFor(row, action),
            // The archive route defaults a bodyless POST to "archived"; the
            // unpublish edge must explicitly target draft, or it would archive.
            action === 'unpublish' ? { status: 'draft' } : {},
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
        router.delete(`/admin/articles/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });
    };

    const columns: DataColumn<ArticleRow>[] = [
        {
            key: 'title',
            header: t('articles.col.title'),
            cell: (row) => (
                <div className="flex flex-col">
                    <span className="font-medium">{row.title}</span>
                    <span className="font-mono text-xs text-neutral-400 dark:text-slate-500">
                        {row.slug}
                    </span>
                </div>
            ),
        },
        {
            key: 'category',
            header: t('articles.col.category'),
            cell: (row) => row.category_name,
        },
        {
            key: 'author',
            header: t('articles.col.author'),
            cell: (row) => row.author_name,
        },
        {
            key: 'status',
            header: t('articles.col.status'),
            cell: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'published_at',
            header: t('articles.col.published_at'),
            cell: (row) => (
                <span className="text-neutral-500 tabular-nums dark:text-slate-400">
                    {formatDate(row.published_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: t('content.col.actions'),
            align: 'right',
            cell: (row) => {
                const actions = ACTIONS_FOR_STATUS[row.status as ArticleStatusValue] ?? [];
                return (
                    <div className="flex flex-wrap justify-end gap-2">
                        <Link
                            href={`/admin/articles/${row.id}/edit`}
                            className="inline-flex h-9 items-center rounded-md border border-neutral-300 px-3 text-sm font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                        >
                            {t('content.edit')}
                        </Link>
                        {actions.map((action) => (
                            <button
                                key={action}
                                type="button"
                                onClick={() => setPending({ row, action })}
                                className="inline-flex h-9 items-center rounded-md border border-primary/40 px-3 text-sm font-medium text-primary transition-colors hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                            >
                                {actionLabel(action, row.status)}
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
            title={t('articles.title')}
            subtitle={t('articles.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <Link
                    href="/admin/articles/create"
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('articles.action.new')}
                </Link>
            }
        >
            <div
                role="group"
                aria-label={t('articles.filter.label')}
                className="mb-6 flex flex-wrap gap-2"
            >
                {STATUS_FILTERS.map((status) => {
                    const active =
                        (filters.status ?? 'all') === status ||
                        (status === 'all' && !filters.status);
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
                            {status === 'all'
                                ? t('articles.filter.all')
                                : t(`article_status.${status}`)}
                        </button>
                    );
                })}
            </div>

            <DataTable
                caption={t('articles.title')}
                columns={columns}
                rows={articles.data}
                rowKey={(row) => row.id}
                emptyMessage={t('articles.empty')}
            />

            {/* Pagination labels are SERVER-GENERATED, trusted strings (Laravel's
                paginator emits "&laquo; Previous" etc. as HTML entities) — NOT
                user content. dangerouslySetInnerHTML here decodes those entities;
                it never touches article body content, which is rendered safely by
                RichTextRenderer (no raw HTML injection). */}
            {articles.links.length > 3 ? (
                <nav
                    aria-label={t('content.pagination')}
                    className="mt-6 flex flex-wrap items-center justify-center gap-1"
                >
                    {articles.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                className={`inline-flex h-9 min-w-9 items-center justify-center rounded-md px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none ${
                                    link.active
                                        ? 'bg-primary text-white'
                                        : 'border border-neutral-300 text-neutral-700 hover:border-primary hover:text-primary dark:border-border-dark dark:text-slate-300'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={index}
                                aria-hidden="true"
                                className="inline-flex h-9 min-w-9 items-center justify-center rounded-md px-3 text-sm text-neutral-400 dark:text-slate-600"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </nav>
            ) : null}

            {pending ? (
                <ConfirmDialog
                    title={actionLabel(pending.action, pending.row.status)}
                    message={t('articles.transition.confirm')
                        .replace('{title}', pending.row.title)
                        .replace('{status}', actionTargetLabel(pending.action))}
                    confirmLabel={actionLabel(pending.action, pending.row.status)}
                    processing={processing}
                    onConfirm={confirmTransition}
                    onCancel={() => setPending(null)}
                />
            ) : null}

            {deleting ? (
                <ConfirmDialog
                    title={t('articles.delete.title')}
                    message={t('articles.delete.confirm').replace('{title}', deleting.title)}
                    processing={processing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
