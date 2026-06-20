import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import SelectField from '@/Components/form/SelectField';
import ImageField from '@/Components/form/ImageField';

/**
 * Shared create/edit director form (slice 003, ORG-04 — one director per org).
 * One form serves store and update — mirroring the single `DirectorData` DTO.
 * Driven by Inertia's useForm so server validation surfaces as 302 + session
 * errors (NEVER 422), shown inline per field via the form primitives' `error`
 * slots.
 *
 * The submit payload is snake_case and matches App\Domain\Organization\Data\
 * DirectorData exactly: { organization_id, first_name, last_name, photo }. The
 * photo rides as a File (multipart) under `photo`; useForm auto-switches to
 * FormData when a File is present (forceFormData makes it deterministic).
 *
 * The one-director-per-org rule (ORG-04, DirectorAlreadyAssignedException) is
 * enforced server-side and surfaces as a `director` field error on the org select.
 *
 * Method spoofing: Edit submits as POST with `_method: 'put'` so the file upload
 * survives — the controller's PUT route still resolves.
 */
export interface OrganizationOption {
    id: number;
    name: string;
}

export interface DirectorFormInitial {
    organization_id: number | '';
    first_name: string;
    last_name: string;
    /** Existing stored photo URL (Edit only). */
    photo_url: string | null;
}

interface DirectorFormState {
    organization_id: number | '';
    first_name: string;
    last_name: string;
    photo: File | null;
}

interface DirectorFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    organizations: OrganizationOption[];
    initial: DirectorFormInitial;
}

export const EMPTY_DIRECTOR: DirectorFormInitial = {
    organization_id: '',
    first_name: '',
    last_name: '',
    photo_url: null,
};

export default function DirectorForm({ mode, action, organizations, initial }: DirectorFormProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, transform } = useForm<DirectorFormState>({
        organization_id: initial.organization_id,
        first_name: initial.first_name,
        last_name: initial.last_name,
        photo: null,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'edit') {
            transform((payload) => ({ ...payload, _method: 'put' }));
        }
        post(action, { preserveScroll: true, forceFormData: true });
    };

    const organizationOptions = organizations.map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    return (
        <form onSubmit={submit} noValidate className="grid max-w-2xl gap-6">
            <SelectField
                label={t('directors.field.organization')}
                value={data.organization_id}
                onChange={(value) => setData('organization_id', value === '' ? '' : Number(value))}
                options={organizationOptions}
                placeholder={t('directors.field.organization_placeholder')}
                error={errors.organization_id}
                required
            />

            <div className="grid gap-6 sm:grid-cols-2">
                <TextField
                    label={t('directors.field.first_name')}
                    name="first_name"
                    value={data.first_name}
                    onChange={(value) => setData('first_name', value)}
                    error={errors.first_name}
                    required
                    maxLength={100}
                    autoFocus
                />

                <TextField
                    label={t('directors.field.last_name')}
                    name="last_name"
                    value={data.last_name}
                    onChange={(value) => setData('last_name', value)}
                    error={errors.last_name}
                    required
                    maxLength={100}
                />
            </div>

            <ImageField
                label={t('directors.field.photo')}
                value={data.photo}
                onChange={(file) => setData('photo', file)}
                currentUrl={initial.photo_url}
                previewAlt={t('directors.field.photo_alt')}
                removeLabel={t('directors.field.photo_remove')}
                error={errors.photo}
                hint={t('directors.field.photo_hint')}
            />

            <button
                type="submit"
                disabled={processing}
                className="inline-flex h-11 w-fit items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
            >
                {mode === 'create' ? t('directors.action.create') : t('directors.action.save')}
            </button>
        </form>
    );
}
