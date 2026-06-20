import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import FormModal from '@/Components/content/FormModal';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import TextField from '@/Components/form/TextField';
import TextArea from '@/Components/form/TextArea';

/**
 * Category management (SPEC §3.3 CAT-01/02, §7.2). role:editor-gated.
 *
 * Props are snake_case, matching Admin\CategoryController::index EXACTLY:
 *   { categories: { id, name, slug, description, articles_count }[] }.
 *
 * Create/edit run through a shared FormModal driven by Inertia useForm — server
 * validation surfaces as 302 + session errors (NEVER 422); the form mirrors
 * App\Domain\Content\Data\CategoryData (name, slug, description). Delete goes
 * through ConfirmDialog → router.delete; CAT-02's in-use guard
 * (CategoryInUseException) is rendered server-side to a `category` field error
 * (bootstrap/app.php) — shown here as a role="alert" banner, and the row is
 * never removed (the delete failed gracefully, never a 500).
 */
interface CategoryRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    articles_count: number;
}

interface CategoriesIndexProps {
    categories: CategoryRow[];
}

type CategoryFormValues = {
    name: string;
    slug: string;
    description: string;
};

const BASE_ROUTE = '/admin/categories';
const EMPTY: CategoryFormValues = { name: '', slug: '', description: '' };

export default function CategoriesIndex({ categories }: CategoriesIndexProps) {
    const { t } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<CategoryRow | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm<CategoryFormValues>(EMPTY);

    // CAT-02 in-use guard surfaces as a `category` field error or a flash.error.
    const blockMessage = pageErrors?.category ?? flash?.error;

    const openCreate = () => {
        reset();
        clearErrors();
        setData(EMPTY);
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (row: CategoryRow) => {
        clearErrors();
        setData({ name: row.name, slug: row.slug, description: row.description ?? '' });
        setEditing(row);
        setOpen(true);
    };

    const close = () => {
        setOpen(false);
        setEditing(null);
        reset();
        clearErrors();
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (editing) {
            put(`${BASE_ROUTE}/${editing.id}`, { preserveScroll: true, onSuccess: close });
        } else {
            post(BASE_ROUTE, { preserveScroll: true, onSuccess: close });
        }
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }
        setDeleteProcessing(true);
        router.delete(`${BASE_ROUTE}/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteProcessing(false);
                setDeleting(null);
            },
        });
    };

    const columns: DataColumn<CategoryRow>[] = [
        {
            key: 'name',
            header: t('categories.col.name'),
            cell: (row) => <span className="font-medium">{row.name}</span>,
        },
        {
            key: 'slug',
            header: t('categories.col.slug'),
            cell: (row) => row.slug,
            cellClassName: 'font-mono text-neutral-500 dark:text-slate-400',
        },
        {
            key: 'articles_count',
            header: t('categories.col.articles'),
            align: 'right',
            cell: (row) => <span className="tabular-nums">{row.articles_count}</span>,
        },
        {
            key: 'actions',
            header: t('content.col.actions'),
            align: 'right',
            cell: (row) => (
                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => openEdit(row)}
                        className="inline-flex h-9 items-center rounded-md border border-neutral-300 px-3 text-sm font-medium text-neutral-800 transition-colors hover:border-primary hover:text-primary focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none dark:border-border-dark dark:text-slate-200"
                    >
                        {t('content.edit')}
                    </button>
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
            title={t('categories.title')}
            subtitle={t('categories.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <button
                    type="button"
                    onClick={openCreate}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('categories.action.new')}
                </button>
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
                caption={t('categories.title')}
                columns={columns}
                rows={categories}
                rowKey={(row) => row.id}
                emptyMessage={t('categories.empty')}
            />

            {open ? (
                <FormModal
                    mode={editing ? 'edit' : 'create'}
                    subject={t('categories.subject')}
                    processing={processing}
                    onSubmit={submit}
                    onCancel={close}
                >
                    <TextField
                        label={t('categories.col.name')}
                        value={data.name}
                        onChange={(value) => setData('name', value)}
                        error={errors.name}
                        required
                        maxLength={30}
                    />
                    <TextField
                        label={t('categories.col.slug')}
                        value={data.slug}
                        onChange={(value) => setData('slug', value)}
                        error={errors.slug}
                        maxLength={50}
                        hint={t('categories.field.slug_hint')}
                    />
                    <TextArea
                        label={t('categories.field.description')}
                        value={data.description}
                        onChange={(value) => setData('description', value)}
                        error={errors.description}
                        rows={2}
                        maxLength={250}
                    />
                </FormModal>
            ) : null}

            {deleting ? (
                <ConfirmDialog
                    title={t('categories.delete.title')}
                    message={t('categories.delete.confirm').replace('{name}', deleting.name)}
                    processing={deleteProcessing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
