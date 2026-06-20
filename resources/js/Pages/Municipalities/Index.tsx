import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import FormModal from '@/Components/content/FormModal';
import ConfirmDialog from '@/Components/content/ConfirmDialog';
import TextField from '@/Components/form/TextField';

/**
 * Municipality catalog management (SPEC §3.2 ORG-01 dependency, §6.3.1).
 * role:administrator-gated. Mirrors the slice-002 Categories/Index inline-CRUD
 * pattern exactly (a shared FormModal + ConfirmDialog), themed magenta /
 * dark-light, a11y.
 *
 * Props are snake_case, matching Admin\MunicipalityController::index EXACTLY:
 *   { municipalities: { id, name, state, organizations_count }[] }.
 *
 * Create/edit run through a shared FormModal driven by Inertia useForm — server
 * validation surfaces as 302 + session errors (NEVER 422); the form mirrors
 * App\Domain\Organization\Data\MunicipalityData (name, state). Delete goes through
 * ConfirmDialog → router.delete; the in-use guard (MunicipalityInUseException) is
 * rendered server-side to a `municipality` field error (bootstrap/app.php) — shown
 * here as a role="alert" banner, and the row is never removed (the delete failed
 * gracefully, never a 500).
 */
interface MunicipalityRow {
    id: number;
    name: string;
    state: string;
    organizations_count: number;
}

interface MunicipalitiesIndexProps {
    municipalities: MunicipalityRow[];
}

type MunicipalityFormValues = {
    name: string;
    state: string;
};

const BASE_ROUTE = '/admin/municipalities';
const EMPTY: MunicipalityFormValues = { name: '', state: '' };

export default function MunicipalitiesIndex({ municipalities }: MunicipalitiesIndexProps) {
    const { t } = useLocale();
    const { errors: pageErrors, flash } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<MunicipalityRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<MunicipalityRow | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const { data, setData, post, put, processing, errors, reset, clearErrors } =
        useForm<MunicipalityFormValues>(EMPTY);

    // The in-use guard surfaces as a `municipality` field error or a flash.error.
    const blockMessage = pageErrors?.municipality ?? flash?.error;

    const openCreate = () => {
        reset();
        clearErrors();
        setData(EMPTY);
        setEditing(null);
        setOpen(true);
    };

    const openEdit = (row: MunicipalityRow) => {
        clearErrors();
        setData({ name: row.name, state: row.state });
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

    const columns: DataColumn<MunicipalityRow>[] = [
        {
            key: 'name',
            header: t('municipalities.col.name'),
            cell: (row) => <span className="font-medium">{row.name}</span>,
        },
        {
            key: 'state',
            header: t('municipalities.col.state'),
            cell: (row) => row.state,
        },
        {
            key: 'organizations_count',
            header: t('municipalities.col.organizations'),
            align: 'right',
            cell: (row) => <span className="tabular-nums">{row.organizations_count}</span>,
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
            title={t('municipalities.title')}
            subtitle={t('municipalities.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
            toolbar={
                <button
                    type="button"
                    onClick={openCreate}
                    className="inline-flex h-11 items-center rounded-lg bg-primary px-5 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none"
                >
                    {t('municipalities.action.new')}
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
                caption={t('municipalities.title')}
                columns={columns}
                rows={municipalities}
                rowKey={(row) => row.id}
                emptyMessage={t('municipalities.empty')}
            />

            {open ? (
                <FormModal
                    mode={editing ? 'edit' : 'create'}
                    subject={t('municipalities.subject')}
                    processing={processing}
                    onSubmit={submit}
                    onCancel={close}
                >
                    <TextField
                        label={t('municipalities.field.name')}
                        value={data.name}
                        onChange={(value) => setData('name', value)}
                        error={errors.name}
                        required
                        maxLength={100}
                    />
                    <TextField
                        label={t('municipalities.field.state')}
                        value={data.state}
                        onChange={(value) => setData('state', value)}
                        error={errors.state}
                        required
                        maxLength={100}
                    />
                </FormModal>
            ) : null}

            {deleting ? (
                <ConfirmDialog
                    title={t('municipalities.delete.title')}
                    message={t('municipalities.delete.confirm').replace('{name}', deleting.name)}
                    processing={deleteProcessing}
                    onConfirm={confirmDelete}
                    onCancel={() => setDeleting(null)}
                />
            ) : null}
        </AdminShell>
    );
}
