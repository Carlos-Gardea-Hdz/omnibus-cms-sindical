import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import SelectField from '@/Components/form/SelectField';
import ImageField from '@/Components/form/ImageField';

/**
 * Shared create/edit organization form (slice 003, ORG-01). One form serves store
 * and update — mirroring the single `OrganizationData` DTO. Driven by Inertia's
 * useForm so server validation surfaces as 302 + session errors (NEVER 422),
 * shown inline per field via the form primitives' `error` slots.
 *
 * The submit payload is snake_case and matches App\Domain\Organization\Data\
 * OrganizationData exactly: { name, slug, municipality_id, registered_at, logo }.
 * The logo rides as a File (multipart) under `logo`; useForm auto-switches to
 * FormData when a File is present (forceFormData makes it deterministic).
 *
 * Logo is REQUIRED on create (ORG-01) and optional on edit (only a freshly-picked
 * file replaces the stored one). The server (OrganizationData::rules) is
 * authoritative; the `required` flag here is affordance only.
 *
 * Method spoofing: Edit submits as POST with `_method: 'put'` so the file upload
 * survives (PHP can't parse multipart on a true PUT body) — the controller's PUT
 * route still resolves.
 */
export interface MunicipalityOption {
    id: number;
    name: string;
}

export interface OrganizationFormInitial {
    name: string;
    slug: string;
    municipality_id: number | '';
    registered_at: string;
    /** Existing stored logo URL (Edit only). */
    logo_url: string | null;
}

interface OrganizationFormState {
    name: string;
    slug: string;
    municipality_id: number | '';
    registered_at: string;
    logo: File | null;
}

interface OrganizationFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    municipalities: MunicipalityOption[];
    initial: OrganizationFormInitial;
}

export const EMPTY_ORGANIZATION: OrganizationFormInitial = {
    name: '',
    slug: '',
    municipality_id: '',
    registered_at: '',
    logo_url: null,
};

export default function OrganizationForm({
    mode,
    action,
    municipalities,
    initial,
}: OrganizationFormProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, transform } =
        useForm<OrganizationFormState>({
            name: initial.name,
            slug: initial.slug,
            municipality_id: initial.municipality_id,
            registered_at: initial.registered_at,
            logo: null,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'edit') {
            transform((payload) => ({ ...payload, _method: 'put' }));
        }
        post(action, { preserveScroll: true, forceFormData: true });
    };

    const municipalityOptions = municipalities.map((municipality) => ({
        value: municipality.id,
        label: municipality.name,
    }));

    return (
        <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-3">
            <div className="flex flex-col gap-6 lg:col-span-2">
                <TextField
                    label={t('organizations.field.name')}
                    name="name"
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    error={errors.name}
                    required
                    maxLength={100}
                    autoFocus
                />

                <SelectField
                    label={t('organizations.field.municipality')}
                    value={data.municipality_id}
                    onChange={(value) =>
                        setData('municipality_id', value === '' ? '' : Number(value))
                    }
                    options={municipalityOptions}
                    placeholder={t('organizations.field.municipality_placeholder')}
                    error={errors.municipality_id}
                    required
                />

                <TextField
                    label={t('organizations.field.registered_at')}
                    name="registered_at"
                    type="date"
                    value={data.registered_at}
                    onChange={(value) => setData('registered_at', value)}
                    error={errors.registered_at}
                    required
                />
            </div>

            <aside className="flex flex-col gap-6">
                <ImageField
                    label={t('organizations.field.logo')}
                    value={data.logo}
                    onChange={(file) => setData('logo', file)}
                    currentUrl={initial.logo_url}
                    previewAlt={t('organizations.field.logo_alt')}
                    removeLabel={t('organizations.field.logo_remove')}
                    error={errors.logo}
                    hint={t('organizations.field.logo_hint')}
                    required={mode === 'create'}
                />

                <TextField
                    label={t('organizations.field.slug')}
                    name="slug"
                    value={data.slug}
                    onChange={(value) => setData('slug', value)}
                    error={errors.slug}
                    maxLength={120}
                    hint={t('organizations.field.slug_hint')}
                />

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                >
                    {mode === 'create'
                        ? t('organizations.action.create')
                        : t('organizations.action.save')}
                </button>
            </aside>
        </form>
    );
}
