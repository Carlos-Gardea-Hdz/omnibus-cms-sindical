import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import DarkModeToggle from '@/Components/DarkModeToggle';
import TextField from '@/Components/form/TextField';
import TextArea from '@/Components/form/TextArea';
import SelectField from '@/Components/form/SelectField';

/**
 * Public union-membership registration form (slice 005, SPEC §3.6 / §7.3). This is
 * the SOLE create path for a member and it is ANONYMOUS + UNCONFINED — no auth, no
 * OrganizationScope (CONTRACT §0 / Decision F). It POSTs (throttle:5,60) to
 * /membership/register with the App\Domain\Membership\Data\RegisterMemberData
 * shape; the server stamps the new member as MemberStatus::Pending with
 * is_affiliated=false (the public payload carries NEITHER status nor
 * is_affiliated — those are not form fields). An admin later approves/rejects it
 * from the org-scoped review list.
 *
 * The submit payload is snake_case and matches RegisterMemberData EXACTLY, in DTO
 * order: { first_name, last_name_paternal, last_name_maternal, curp, rfc,
 * date_of_birth, municipality_id, address, postal_code, neighborhood, mobile,
 * phone?, organization_id? }. `organization_id` is OPTIONAL (a prospective member
 * may register without picking an organization — the column is nullable, SET NULL
 * on org delete); `phone` is optional, `mobile` required.
 *
 * Driven by Inertia's useForm so server validation surfaces as 302 + session
 * errors (NEVER 422), shown inline per field via the form primitives' `error`
 * slots — including the format rules (CURP / RFC / Mexican phone / 5-digit postal
 * code / DOB before today). The CURP / RFC inputs uppercase as the user types,
 * mirroring the server-side strtoupper normalization so what the applicant sees
 * matches what is validated.
 *
 * PII NOTE: this form COLLECTS personal data (CURP, RFC, address, DOB). It NEVER
 * RENDERS another member's PII — there is no public member directory and this page
 * reads no member rows (CONTRACT §10.5 — member data is admin-only). The PII at
 * rest is encrypted server-side (the curp/rfc `encrypted` cast).
 *
 * Public chrome: branded header with the LanguageSwitcher + DarkModeToggle (no
 * auth controls), one #main, one <h1>. Themed magenta, dark/light aware,
 * bilingual via the locale hook.
 */
interface MunicipalityOption {
    id: number;
    name: string;
}

interface OrganizationOption {
    id: number;
    name: string;
}

interface RegisterProps {
    municipality_options: MunicipalityOption[];
    organization_options: OrganizationOption[];
}

interface RegisterFormState {
    first_name: string;
    last_name_paternal: string;
    last_name_maternal: string;
    curp: string;
    rfc: string;
    date_of_birth: string;
    municipality_id: number | '';
    address: string;
    postal_code: string;
    neighborhood: string;
    mobile: string;
    phone: string;
    organization_id: number | '';
}

const EMPTY_FORM: RegisterFormState = {
    first_name: '',
    last_name_paternal: '',
    last_name_maternal: '',
    curp: '',
    rfc: '',
    date_of_birth: '',
    municipality_id: '',
    address: '',
    postal_code: '',
    neighborhood: '',
    mobile: '',
    phone: '',
    organization_id: '',
};

export default function MembershipRegister({
    municipality_options,
    organization_options,
}: RegisterProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors } = useForm<RegisterFormState>(EMPTY_FORM);

    const municipalityOptions = municipality_options.map((municipality) => ({
        value: municipality.id,
        label: municipality.name,
    }));

    const organizationOptions = organization_options.map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/membership/register', { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('membership.register.title')} />
            <div className="min-h-dvh bg-white text-neutral-900 dark:bg-bg-dark dark:text-slate-100">
                <header className="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-md dark:border-border-dark dark:bg-bg-dark/80">
                    <div className="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                        <Link
                            href="/"
                            className="flex items-center gap-2 text-lg font-extrabold tracking-tight"
                        >
                            <span className="grid h-8 w-8 place-items-center rounded-lg bg-gradient-to-br from-primary to-primary-dark text-white">
                                C
                            </span>
                            <span className="text-gradient-primary">{t('app.name')}</span>
                        </Link>
                        <nav className="flex items-center gap-2" aria-label={t('nav.utilities')}>
                            <LanguageSwitcher />
                            <DarkModeToggle />
                        </nav>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-3xl px-6 py-12">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-1 text-sm font-medium text-primary transition-colors hover:text-primary-dark focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <span aria-hidden="true">&larr;</span>
                        {t('membership.register.back_home')}
                    </Link>

                    <h1 className="mt-4 text-4xl font-extrabold tracking-tight">
                        {t('membership.register.title')}
                    </h1>
                    <p className="mt-3 text-lg text-neutral-600 dark:text-slate-300">
                        {t('membership.register.subtitle')}
                    </p>

                    <form onSubmit={submit} noValidate className="mt-8 grid gap-6">
                        <fieldset className="grid gap-6 rounded-2xl border border-neutral-200 p-5 sm:grid-cols-2 dark:border-border-dark">
                            <legend className="px-1 text-sm font-semibold text-neutral-700 dark:text-slate-300">
                                {t('membership.register.legend.identity')}
                            </legend>

                            <TextField
                                label={t('members.field.first_name')}
                                name="first_name"
                                value={data.first_name}
                                onChange={(value) => setData('first_name', value)}
                                error={errors.first_name}
                                required
                                maxLength={100}
                                autoComplete="given-name"
                                autoFocus
                            />

                            <TextField
                                label={t('members.field.last_name_paternal')}
                                name="last_name_paternal"
                                value={data.last_name_paternal}
                                onChange={(value) => setData('last_name_paternal', value)}
                                error={errors.last_name_paternal}
                                required
                                maxLength={100}
                                autoComplete="family-name"
                            />

                            <TextField
                                label={t('members.field.last_name_maternal')}
                                name="last_name_maternal"
                                value={data.last_name_maternal}
                                onChange={(value) => setData('last_name_maternal', value)}
                                error={errors.last_name_maternal}
                                required
                                maxLength={100}
                                autoComplete="additional-name"
                            />

                            <TextField
                                label={t('members.field.date_of_birth')}
                                name="date_of_birth"
                                type="date"
                                value={data.date_of_birth}
                                onChange={(value) => setData('date_of_birth', value)}
                                error={errors.date_of_birth}
                                hint={t('members.field.date_of_birth_hint')}
                                required
                            />

                            <TextField
                                label={t('members.field.curp')}
                                name="curp"
                                value={data.curp}
                                onChange={(value) => setData('curp', value.toUpperCase())}
                                error={errors.curp}
                                hint={t('members.field.curp_hint')}
                                required
                                maxLength={18}
                                autoComplete="off"
                            />

                            <TextField
                                label={t('members.field.rfc')}
                                name="rfc"
                                value={data.rfc}
                                onChange={(value) => setData('rfc', value.toUpperCase())}
                                error={errors.rfc}
                                hint={t('members.field.rfc_hint')}
                                required
                                maxLength={13}
                                autoComplete="off"
                            />
                        </fieldset>

                        <fieldset className="grid gap-6 rounded-2xl border border-neutral-200 p-5 sm:grid-cols-2 dark:border-border-dark">
                            <legend className="px-1 text-sm font-semibold text-neutral-700 dark:text-slate-300">
                                {t('membership.register.legend.contact')}
                            </legend>

                            <div className="sm:col-span-2">
                                <TextArea
                                    label={t('members.field.address')}
                                    value={data.address}
                                    onChange={(value) => setData('address', value)}
                                    error={errors.address}
                                    rows={2}
                                    required
                                />
                            </div>

                            <TextField
                                label={t('members.field.neighborhood')}
                                name="neighborhood"
                                value={data.neighborhood}
                                onChange={(value) => setData('neighborhood', value)}
                                error={errors.neighborhood}
                                required
                                maxLength={100}
                            />

                            <TextField
                                label={t('members.field.postal_code')}
                                name="postal_code"
                                value={data.postal_code}
                                onChange={(value) => setData('postal_code', value)}
                                error={errors.postal_code}
                                hint={t('members.field.postal_code_hint')}
                                required
                                maxLength={5}
                                inputMode="numeric"
                                autoComplete="postal-code"
                            />

                            <TextField
                                label={t('members.field.mobile')}
                                name="mobile"
                                value={data.mobile}
                                onChange={(value) => setData('mobile', value)}
                                error={errors.mobile}
                                hint={t('members.field.phone_hint')}
                                required
                                maxLength={10}
                                inputMode="tel"
                                autoComplete="tel"
                            />

                            <TextField
                                label={t('members.field.phone')}
                                name="phone"
                                value={data.phone}
                                onChange={(value) => setData('phone', value)}
                                error={errors.phone}
                                hint={t('members.field.phone_optional_hint')}
                                maxLength={10}
                                inputMode="tel"
                            />
                        </fieldset>

                        <fieldset className="grid gap-6 rounded-2xl border border-neutral-200 p-5 sm:grid-cols-2 dark:border-border-dark">
                            <legend className="px-1 text-sm font-semibold text-neutral-700 dark:text-slate-300">
                                {t('membership.register.legend.affiliation')}
                            </legend>

                            <SelectField
                                label={t('members.field.municipality')}
                                value={data.municipality_id}
                                onChange={(value) =>
                                    setData('municipality_id', value === '' ? '' : Number(value))
                                }
                                options={municipalityOptions}
                                placeholder={t('members.field.municipality_placeholder')}
                                error={errors.municipality_id}
                                required
                            />

                            <SelectField
                                label={t('members.field.organization')}
                                value={data.organization_id}
                                onChange={(value) =>
                                    setData('organization_id', value === '' ? '' : Number(value))
                                }
                                options={organizationOptions}
                                placeholder={t('members.field.organization_placeholder')}
                                error={errors.organization_id}
                                hint={t('members.field.organization_hint')}
                            />
                        </fieldset>

                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                            >
                                {t('membership.register.submit')}
                            </button>
                        </div>
                    </form>
                </main>
            </div>
        </>
    );
}
