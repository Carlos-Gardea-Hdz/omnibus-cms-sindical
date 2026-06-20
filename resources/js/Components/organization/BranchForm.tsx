import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import SelectField from '@/Components/form/SelectField';

/**
 * Shared create/edit branch form (slice 003, ORG-02). One form serves store and
 * update — mirroring the single `BranchData` DTO. Driven by Inertia's useForm so
 * server validation surfaces as 302 + session errors (NEVER 422), shown inline
 * per field via the form primitives' `error` slots.
 *
 * The submit payload is snake_case and matches App\Domain\Organization\Data\
 * BranchData exactly: { organization_id, name, location }. No files — a plain
 * PUT/POST. The organization picker lists only the orgs the acting user may
 * branch under (manager → their own org; the server is authoritative — the
 * OrganizationScope confines what manager-gated routes can touch).
 */
export interface OrganizationOption {
    id: number;
    name: string;
}

export interface BranchFormInitial {
    organization_id: number | '';
    name: string;
    location: string;
}

interface BranchFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    organizations: OrganizationOption[];
    initial: BranchFormInitial;
}

export const EMPTY_BRANCH: BranchFormInitial = {
    organization_id: '',
    name: '',
    location: '',
};

export default function BranchForm({ mode, action, organizations, initial }: BranchFormProps) {
    const { t } = useLocale();

    const { data, setData, post, put, processing, errors } = useForm<BranchFormInitial>({
        organization_id: initial.organization_id,
        name: initial.name,
        location: initial.location,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'edit') {
            put(action, { preserveScroll: true });
        } else {
            post(action, { preserveScroll: true });
        }
    };

    const organizationOptions = organizations.map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    return (
        <form onSubmit={submit} noValidate className="grid max-w-2xl gap-6">
            <SelectField
                label={t('branches.field.organization')}
                value={data.organization_id}
                onChange={(value) => setData('organization_id', value === '' ? '' : Number(value))}
                options={organizationOptions}
                placeholder={t('branches.field.organization_placeholder')}
                error={errors.organization_id}
                required
            />

            <TextField
                label={t('branches.field.name')}
                name="name"
                value={data.name}
                onChange={(value) => setData('name', value)}
                error={errors.name}
                required
                maxLength={100}
                autoFocus
            />

            <TextField
                label={t('branches.field.location')}
                name="location"
                value={data.location}
                onChange={(value) => setData('location', value)}
                error={errors.location}
                required
                maxLength={100}
            />

            <button
                type="submit"
                disabled={processing}
                className="inline-flex h-11 w-fit items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
            >
                {mode === 'create' ? t('branches.action.create') : t('branches.action.save')}
            </button>
        </form>
    );
}
