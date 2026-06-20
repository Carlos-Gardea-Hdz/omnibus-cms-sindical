import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import SelectField from '@/Components/form/SelectField';
import CheckboxField from '@/Components/form/CheckboxField';
import ImageField from '@/Components/form/ImageField';

/**
 * Shared create/edit representative form (slice 003, ORG-03). One form serves
 * store and update — mirroring the single `RepresentativeData` DTO. Driven by
 * Inertia's useForm so server validation surfaces as 302 + session errors (NEVER
 * 422), shown inline per field via the form primitives' `error` slots.
 *
 * The submit payload is snake_case and matches App\Domain\Organization\Data\
 * RepresentativeData exactly: { organization_id, branch_id, first_name,
 * last_name, shift, is_coordinator, photo }. The photo rides as a File
 * (multipart) under `photo`.
 *
 * The branch select is FILTERED client-side by the chosen organization (each
 * BranchOption carries its `organization_id`), so a representative is always
 * assigned to a branch within the selected org. Changing the organization clears
 * a now-invalid branch selection. The server re-validates (Exists + the
 * OrganizationScope) — the client filter is affordance only.
 *
 * Method spoofing: Edit submits as POST with `_method: 'put'` so the file upload
 * survives — the controller's PUT route still resolves.
 *
 * TYPE-ONLY contract: we never value-import the generated enum (the generated file
 * is types-only; value-importing it would break the Vite build). We model the
 * backing values of App\Domain\Organization\Enums\RepresentativeShift as a local
 * string union (`ShiftValue`) — exactly the slice-002 StatusBadge approach — and
 * the labels resolve client-side via `t('representative_shift.<value>')`.
 */
export type ShiftValue = 'morning' | 'evening' | 'night';

const SHIFT_VALUES: readonly ShiftValue[] = ['morning', 'evening', 'night'];

export interface OrganizationOption {
    id: number;
    name: string;
}

export interface BranchOption {
    id: number;
    name: string;
    organization_id: number;
}

export interface RepresentativeFormInitial {
    organization_id: number | '';
    branch_id: number | '';
    first_name: string;
    last_name: string;
    shift: ShiftValue | '';
    is_coordinator: boolean;
    /** Existing stored photo URL (Edit only). */
    photo_url: string | null;
}

interface RepresentativeFormState {
    organization_id: number | '';
    branch_id: number | '';
    first_name: string;
    last_name: string;
    shift: ShiftValue | '';
    is_coordinator: boolean;
    photo: File | null;
}

interface RepresentativeFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    organizations: OrganizationOption[];
    branches: BranchOption[];
    initial: RepresentativeFormInitial;
}

export const EMPTY_REPRESENTATIVE: RepresentativeFormInitial = {
    organization_id: '',
    branch_id: '',
    first_name: '',
    last_name: '',
    shift: '',
    is_coordinator: false,
    photo_url: null,
};

export default function RepresentativeForm({
    mode,
    action,
    organizations,
    branches,
    initial,
}: RepresentativeFormProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, transform } =
        useForm<RepresentativeFormState>({
            organization_id: initial.organization_id,
            branch_id: initial.branch_id,
            first_name: initial.first_name,
            last_name: initial.last_name,
            shift: initial.shift,
            is_coordinator: initial.is_coordinator,
            photo: null,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'edit') {
            transform((payload) => ({ ...payload, _method: 'put' }));
        }
        post(action, { preserveScroll: true, forceFormData: true });
    };

    const onOrganizationChange = (value: string) => {
        const nextOrg = value === '' ? '' : Number(value);
        setData('organization_id', nextOrg);
        // Clear a branch that no longer belongs to the newly-selected org.
        const branchStillValid = branches.some(
            (branch) => branch.id === data.branch_id && branch.organization_id === nextOrg,
        );
        if (!branchStillValid) {
            setData('branch_id', '');
        }
    };

    const organizationOptions = organizations.map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    const branchOptions = useMemo(
        () =>
            branches
                .filter((branch) =>
                    data.organization_id === ''
                        ? false
                        : branch.organization_id === data.organization_id,
                )
                .map((branch) => ({ value: branch.id, label: branch.name })),
        [branches, data.organization_id],
    );

    const shiftOptions = SHIFT_VALUES.map((shift) => ({
        value: shift,
        label: t(`representative_shift.${shift}`),
    }));

    return (
        <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-3">
            <div className="flex flex-col gap-6 lg:col-span-2">
                <div className="grid gap-6 sm:grid-cols-2">
                    <SelectField
                        label={t('representatives.field.organization')}
                        value={data.organization_id}
                        onChange={onOrganizationChange}
                        options={organizationOptions}
                        placeholder={t('representatives.field.organization_placeholder')}
                        error={errors.organization_id}
                        required
                    />

                    <SelectField
                        label={t('representatives.field.branch')}
                        value={data.branch_id}
                        onChange={(value) =>
                            setData('branch_id', value === '' ? '' : Number(value))
                        }
                        options={branchOptions}
                        placeholder={t('representatives.field.branch_placeholder')}
                        error={errors.branch_id}
                        hint={
                            data.organization_id === ''
                                ? t('representatives.field.branch_hint')
                                : undefined
                        }
                        disabled={data.organization_id === ''}
                        required
                    />
                </div>

                <div className="grid gap-6 sm:grid-cols-2">
                    <TextField
                        label={t('representatives.field.first_name')}
                        name="first_name"
                        value={data.first_name}
                        onChange={(value) => setData('first_name', value)}
                        error={errors.first_name}
                        required
                        maxLength={100}
                        autoFocus
                    />

                    <TextField
                        label={t('representatives.field.last_name')}
                        name="last_name"
                        value={data.last_name}
                        onChange={(value) => setData('last_name', value)}
                        error={errors.last_name}
                        required
                        maxLength={100}
                    />
                </div>

                <SelectField
                    label={t('representatives.field.shift')}
                    value={data.shift}
                    onChange={(value) => setData('shift', value === '' ? '' : (value as ShiftValue))}
                    options={shiftOptions}
                    placeholder={t('representatives.field.shift_placeholder')}
                    error={errors.shift}
                    required
                />
            </div>

            <aside className="flex flex-col gap-6">
                <CheckboxField
                    label={t('representatives.field.is_coordinator')}
                    checked={data.is_coordinator}
                    onChange={(checked) => setData('is_coordinator', checked)}
                    error={errors.is_coordinator}
                />

                <ImageField
                    label={t('representatives.field.photo')}
                    value={data.photo}
                    onChange={(file) => setData('photo', file)}
                    currentUrl={initial.photo_url}
                    previewAlt={t('representatives.field.photo_alt')}
                    removeLabel={t('representatives.field.photo_remove')}
                    error={errors.photo}
                    hint={t('representatives.field.photo_hint')}
                />

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                >
                    {mode === 'create'
                        ? t('representatives.action.create')
                        : t('representatives.action.save')}
                </button>
            </aside>
        </form>
    );
}
