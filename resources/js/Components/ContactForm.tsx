import { useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { PageProps } from '@/types';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import TextArea from '@/Components/form/TextArea';
import SelectField from '@/Components/form/SelectField';

/**
 * Public contact-message form (slice 006, SPEC §3.5 / §8.3 / Decision G). This is
 * an embedded COMPONENT — it lives inside the landing page / footer rather than a
 * standalone GET /contact screen — and it is the SOLE create path for a
 * `contact_messages` row. It is ANONYMOUS + UNCONFINED (no auth, no
 * OrganizationScope): the org/branch provenance comes from the public payload and
 * the server asserts branch-belongs-to-org (CONTRACT §4/§5), so a forged
 * branch/org pair is rejected (302 + `branch_id` error), not silently stored.
 *
 * The submit payload is snake_case and matches App\Domain\Engagement\Data\
 * SubmitContactData EXACTLY, in DTO order:
 *   { first_name, last_name, email, phone, message, organization_id, branch_id }.
 * There is NO server-owned field to forge — `contact_messages` has no
 * status/moderation/spam column (CONTRACT §0), so the table is its own terminal
 * state. The row is a permanent audit record; this form never reads one back.
 *
 * Driven by Inertia's useForm so server validation surfaces as 302 + session
 * errors (NEVER 422), shown inline per field via the form primitives' `error`
 * slots — including the Mexican-phone rule and the branch→org mismatch. On a
 * successful POST the server flashes `contact.submitted`, surfaced here as a
 * role="status" banner (WCAG 2.2 status message) and the form resets.
 *
 * The branch select is DEPENDENT on the chosen organization: each
 * `organization_options` entry nests its own `branches`, so selecting an org
 * filters the branch list client-side WITHOUT a /api/branches round-trip (the
 * §7.6 partial-reload route stays deferred). Changing the org clears the branch.
 *
 * PII NOTE: this form COLLECTS contact data (name, email, phone). It NEVER RENDERS
 * any stored message or another submitter's PII — there is no public read of
 * contact messages (CONTRACT §12 / no-public-read). `message` is plain TEXT, not
 * rich HTML, so SanitizesContent does not apply and nothing here is ever rendered
 * via dangerouslySetInnerHTML.
 *
 * Themed magenta, dark/light aware, bilingual via the locale hook; the embedding
 * page owns the surrounding chrome (header/footer + language/theme toggles).
 */
interface BranchOption {
    id: number;
    name: string;
}

interface OrganizationOption {
    id: number;
    name: string;
    branches: BranchOption[];
}

interface ContactFormProps {
    organization_options: OrganizationOption[];
    /** Optional heading override; defaults to the bilingual contact title. */
    title?: string;
    /** Optional lead paragraph override; defaults to the bilingual subtitle. */
    subtitle?: string;
}

interface ContactFormState {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    message: string;
    organization_id: number | '';
    branch_id: number | '';
}

const EMPTY_FORM: ContactFormState = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    message: '',
    organization_id: '',
    branch_id: '',
};

export default function ContactForm({
    organization_options,
    title,
    subtitle,
}: ContactFormProps) {
    const { t } = useLocale();
    const flash = usePage<PageProps>().props.flash;

    const { data, setData, post, processing, errors, reset } =
        useForm<ContactFormState>(EMPTY_FORM);

    const organizationOptions = organization_options.map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    // The branch select is filtered to the chosen org's nested branches — no
    // /api/branches round-trip (CONTRACT §7). Empty until an org is picked.
    const branchOptions = useMemo(() => {
        if (data.organization_id === '') {
            return [];
        }
        const organization = organization_options.find(
            (candidate) => candidate.id === data.organization_id,
        );
        return (organization?.branches ?? []).map((branch) => ({
            value: branch.id,
            label: branch.name,
        }));
    }, [organization_options, data.organization_id]);

    const onOrganizationChange = (value: string) => {
        // Changing the org clears the now-stale branch so the payload never
        // carries a branch from a different org (the server re-asserts anyway).
        setData((current) => ({
            ...current,
            organization_id: value === '' ? '' : Number(value),
            branch_id: '',
        }));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // Literal path: this codebase ships no Ziggy `route()` helper — every
        // form (membership/jobs/articles) posts to a string. Mirrors the
        // contact.store route (POST /contact, throttle:3,15).
        post('/contact', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <div className="rounded-2xl border border-neutral-200 bg-white p-6 sm:p-8 dark:border-border-dark dark:bg-surface-dark">
            <h2 className="text-2xl font-bold tracking-tight">
                {title ?? t('contact.title')}
            </h2>
            <p className="mt-2 text-neutral-600 dark:text-slate-300">
                {subtitle ?? t('contact.subtitle')}
            </p>

            {flash?.success ? (
                <p
                    role="status"
                    className="mt-6 rounded-lg border border-primary/30 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-dark dark:bg-primary/10 dark:text-primary-light"
                >
                    {flash.success}
                </p>
            ) : null}

            <form onSubmit={submit} noValidate className="mt-6 grid gap-6 sm:grid-cols-2">
                <TextField
                    label={t('contact.field.first_name')}
                    name="first_name"
                    value={data.first_name}
                    onChange={(value) => setData('first_name', value)}
                    error={errors.first_name}
                    required
                    maxLength={60}
                    autoComplete="given-name"
                />

                <TextField
                    label={t('contact.field.last_name')}
                    name="last_name"
                    value={data.last_name}
                    onChange={(value) => setData('last_name', value)}
                    error={errors.last_name}
                    required
                    maxLength={60}
                    autoComplete="family-name"
                />

                <TextField
                    label={t('contact.field.email')}
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(value) => setData('email', value)}
                    error={errors.email}
                    required
                    maxLength={60}
                    autoComplete="email"
                    inputMode="email"
                />

                <TextField
                    label={t('contact.field.phone')}
                    name="phone"
                    value={data.phone}
                    onChange={(value) => setData('phone', value)}
                    error={errors.phone}
                    hint={t('contact.field.phone_hint')}
                    required
                    maxLength={10}
                    inputMode="tel"
                    autoComplete="tel"
                />

                <SelectField
                    label={t('contact.field.organization')}
                    value={data.organization_id}
                    onChange={onOrganizationChange}
                    options={organizationOptions}
                    placeholder={t('contact.field.organization_placeholder')}
                    error={errors.organization_id}
                    required
                />

                <SelectField
                    label={t('contact.field.branch')}
                    value={data.branch_id}
                    onChange={(value) =>
                        setData('branch_id', value === '' ? '' : Number(value))
                    }
                    options={branchOptions}
                    placeholder={t('contact.field.branch_placeholder')}
                    error={errors.branch_id}
                    hint={t('contact.field.branch_hint')}
                    required
                    disabled={data.organization_id === ''}
                />

                <div className="sm:col-span-2">
                    <TextArea
                        label={t('contact.field.message')}
                        value={data.message}
                        onChange={(value) => setData('message', value)}
                        error={errors.message}
                        hint={t('contact.field.message_hint')}
                        rows={5}
                        required
                        maxLength={1000}
                    />
                </div>

                <div className="flex justify-end sm:col-span-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                    >
                        {t('contact.submit')}
                    </button>
                </div>
            </form>
        </div>
    );
}
