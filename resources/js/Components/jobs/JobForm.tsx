import { useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import TextArea from '@/Components/form/TextArea';
import SelectField from '@/Components/form/SelectField';

/**
 * Shared create/edit job posting form (slice 004, JOB-01, SPEC §3.4). One form
 * serves store and update — mirroring the single `CreateJobPostingData` DTO.
 * Driven by Inertia's useForm so server validation surfaces as 302 + session
 * errors (NEVER 422), shown inline per field via the form primitives' `error`
 * slots.
 *
 * The submit payload is snake_case and matches App\Domain\Jobs\Data\
 * CreateJobPostingData exactly: { title, description, schedule, contact_info,
 * organization_id, branch_id, salary_min_cents, salary_max_cents,
 * salary_display }. `status` is NOT a form field — the create Action always
 * stamps JobStatus::Active (JOB-01) and updates never change status (the
 * lifecycle toggle on the index owns transitions).
 *
 * The `description` is PLAIN TEXT (CONTRACT Decision A — not TipTap/rich text), so
 * a simple textarea backs it; the server stores it verbatim (no SanitizesContent).
 *
 * The branch select is FILTERED client-side by the chosen organization (each
 * BranchOption carries its `organization_id`), so a job is always assigned to a
 * branch within the selected org. Changing the organization clears a now-invalid
 * branch selection. The server re-validates (the cross-row branch assertion +
 * the OrganizationScope) and a confined manager's payload organization_id is
 * IGNORED server-side — the client org select is affordance only and shown solely
 * to an unconfined admin (passed as `organizations`); a confined manager renders
 * the form without it.
 *
 * Salary is integer cents (NEVER float — SPEC §2.2): two optional number inputs
 * (min/max in cents) plus an optional free-text `salary_display` (e.g.
 * "$15,000 - $20,000 MXN"). The server enforces min ≤ max; the client mirrors the
 * shape only.
 *
 * No file upload, so a plain Inertia post/put (Edit method-spoofs via `put`).
 *
 * TYPE-ONLY contract: we never value-import the generated JobStatus enum.
 */
export interface OrganizationOption {
    id: number;
    name: string;
}

export interface BranchOption {
    id: number;
    name: string;
    organization_id: number;
}

export interface JobFormInitial {
    organization_id: number | '';
    branch_id: number | '';
    title: string;
    description: string;
    schedule: string;
    contact_info: string;
    salary_min_cents: number | '';
    salary_max_cents: number | '';
    salary_display: string;
}

interface JobFormState {
    organization_id: number | '';
    branch_id: number | '';
    title: string;
    description: string;
    schedule: string;
    contact_info: string;
    salary_min_cents: number | '';
    salary_max_cents: number | '';
    salary_display: string;
}

interface JobFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    /** Org options — present only for an unconfined admin (a confined manager gets none). */
    organizations?: OrganizationOption[];
    branches: BranchOption[];
    initial: JobFormInitial;
}

export const EMPTY_JOB: JobFormInitial = {
    organization_id: '',
    branch_id: '',
    title: '',
    description: '',
    schedule: '',
    contact_info: '',
    salary_min_cents: '',
    salary_max_cents: '',
    salary_display: '',
};

export default function JobForm({ mode, action, organizations, branches, initial }: JobFormProps) {
    const { t } = useLocale();

    const { data, setData, post, put, processing, errors } = useForm<JobFormState>({
        organization_id: initial.organization_id,
        branch_id: initial.branch_id,
        title: initial.title,
        description: initial.description,
        schedule: initial.schedule,
        contact_info: initial.contact_info,
        salary_min_cents: initial.salary_min_cents,
        salary_max_cents: initial.salary_max_cents,
        salary_display: initial.salary_display,
    });

    /**
     * An unconfined admin picks the organization; a confined manager has it
     * server-stamped, so the select is rendered only when `organizations` is
     * supplied. When absent we still submit `organization_id` (the server ignores
     * it for a confined caller — see CreateJobPostingAction), pre-seeded from the
     * initial value so the branch filter has an org to match against.
     */
    const showOrgSelect = Array.isArray(organizations) && organizations.length > 0;

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

    const organizationOptions = (organizations ?? []).map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    const branchOptions = useMemo(
        () =>
            branches
                .filter((branch) =>
                    showOrgSelect
                        ? data.organization_id === ''
                            ? false
                            : branch.organization_id === data.organization_id
                        : true,
                )
                .map((branch) => ({ value: branch.id, label: branch.name })),
        [branches, data.organization_id, showOrgSelect],
    );

    const toCents = (value: string): number | '' => (value === '' ? '' : Number(value));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (mode === 'edit') {
            put(action, { preserveScroll: true });
        } else {
            post(action, { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-3">
            <div className="flex flex-col gap-6 lg:col-span-2">
                {showOrgSelect ? (
                    <SelectField
                        label={t('jobs.field.organization')}
                        value={data.organization_id}
                        onChange={onOrganizationChange}
                        options={organizationOptions}
                        placeholder={t('jobs.field.organization_placeholder')}
                        error={errors.organization_id}
                        required
                    />
                ) : null}

                <SelectField
                    label={t('jobs.field.branch')}
                    value={data.branch_id}
                    onChange={(value) => setData('branch_id', value === '' ? '' : Number(value))}
                    options={branchOptions}
                    placeholder={t('jobs.field.branch_placeholder')}
                    error={errors.branch_id}
                    hint={
                        showOrgSelect && data.organization_id === ''
                            ? t('jobs.field.branch_hint')
                            : undefined
                    }
                    disabled={showOrgSelect && data.organization_id === ''}
                    required
                />

                <TextField
                    label={t('jobs.field.title')}
                    name="title"
                    value={data.title}
                    onChange={(value) => setData('title', value)}
                    error={errors.title}
                    required
                    maxLength={100}
                    autoFocus
                />

                <TextArea
                    label={t('jobs.field.description')}
                    value={data.description}
                    onChange={(value) => setData('description', value)}
                    error={errors.description}
                    hint={t('jobs.field.description_hint')}
                    rows={8}
                    required
                />

                <div className="grid gap-6 sm:grid-cols-2">
                    <TextField
                        label={t('jobs.field.schedule')}
                        name="schedule"
                        value={data.schedule}
                        onChange={(value) => setData('schedule', value)}
                        error={errors.schedule}
                        required
                        maxLength={100}
                    />

                    <TextField
                        label={t('jobs.field.contact_info')}
                        name="contact_info"
                        value={data.contact_info}
                        onChange={(value) => setData('contact_info', value)}
                        error={errors.contact_info}
                        required
                        maxLength={100}
                    />
                </div>
            </div>

            <aside className="flex flex-col gap-6">
                <fieldset className="flex flex-col gap-6 rounded-2xl border border-neutral-200 p-5 dark:border-border-dark">
                    <legend className="px-1 text-sm font-semibold text-neutral-700 dark:text-slate-300">
                        {t('jobs.field.salary_legend')}
                    </legend>

                    <TextField
                        label={t('jobs.field.salary_min_cents')}
                        name="salary_min_cents"
                        type="number"
                        min={0}
                        step={1}
                        value={data.salary_min_cents}
                        onChange={(value) => setData('salary_min_cents', toCents(value))}
                        error={errors.salary_min_cents}
                        hint={t('jobs.field.salary_cents_hint')}
                    />

                    <TextField
                        label={t('jobs.field.salary_max_cents')}
                        name="salary_max_cents"
                        type="number"
                        min={0}
                        step={1}
                        value={data.salary_max_cents}
                        onChange={(value) => setData('salary_max_cents', toCents(value))}
                        error={errors.salary_max_cents}
                    />

                    <TextField
                        label={t('jobs.field.salary_display')}
                        name="salary_display"
                        value={data.salary_display}
                        onChange={(value) => setData('salary_display', value)}
                        error={errors.salary_display}
                        hint={t('jobs.field.salary_display_hint')}
                        maxLength={50}
                    />
                </fieldset>

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                >
                    {mode === 'create' ? t('jobs.action.create') : t('jobs.action.save')}
                </button>
            </aside>
        </form>
    );
}
