import { useForm } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import TextField from '@/Components/form/TextField';
import SelectField from '@/Components/form/SelectField';

/**
 * Shared create/edit user form (CMS slice 008, AUTH-04). One form serves store and
 * update — mirroring the `UserData` / `UpdateUserData` Spatie DTOs. Driven by
 * Inertia's useForm so server validation surfaces as 302 + session errors (NEVER
 * 422), shown inline per field via the form primitives' `error` slots.
 *
 * The submit payload is snake_case and matches the DTOs EXACTLY:
 *   create → { username, name, email, password, password_confirmation, role, organization_id }
 *   edit   → same, but password/password_confirmation are sent only when the actor
 *            typed a new password (a blank password means "leave it unchanged").
 *
 * Password is REQUIRED + confirmed on create, optional on edit. The control never
 * pre-fills or echoes a stored password — there is none to show (it is hashed via
 * the cast server-side) and a password must never reach the client.
 *
 * `assignable_roles` is actor-scoped server-side: an administrator's list EXCLUDES
 * super_admin (no-self-elevation / the singleton invariant), so the role <select>
 * can only ever offer legal targets; the server re-checks (CreateUserAction /
 * UpdateUserAction) and an illegal role → 302 + a `role` error.
 *
 * `organizations` is non-null ONLY for a super_admin (cross-org create) — for an
 * administrator the field is hidden and the org is stamped from the actor's context
 * server-side (never from this payload).
 *
 * Edit submits as POST + _method=put so the resource route resolves (no file here,
 * but the spoof keeps the controller's PUT binding intact).
 */
export interface RoleOption {
    value: string;
    label_key: string;
}

export interface OrganizationOption {
    id: number;
    name: string;
}

export interface UserFormInitial {
    username: string;
    name: string;
    email: string;
    role: string;
    organization_id: number | '';
}

interface UserFormState {
    username: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role: string;
    organization_id: number | '';
}

interface UserFormProps {
    mode: 'create' | 'edit';
    /** Resolved submit URL. */
    action: string;
    /** Actor-scoped assignable roles (administrator's list excludes super_admin). */
    assignableRoles: RoleOption[];
    /** Non-null only for a super_admin (cross-org create); null hides the field. */
    organizations: OrganizationOption[] | null;
    initial: UserFormInitial;
    /** Disable identity fields when editing a demo placeholder, if ever surfaced. */
    disabled?: boolean;
}

export const EMPTY_USER: UserFormInitial = {
    username: '',
    name: '',
    email: '',
    role: '',
    organization_id: '',
};

export default function UserForm({
    mode,
    action,
    assignableRoles,
    organizations,
    initial,
    disabled = false,
}: UserFormProps) {
    const { t } = useLocale();

    const { data, setData, post, processing, errors, transform } = useForm<UserFormState>({
        username: initial.username,
        name: initial.name,
        email: initial.email,
        password: '',
        password_confirmation: '',
        role: initial.role,
        organization_id: initial.organization_id,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        transform((payload) => {
            const next: Record<string, unknown> = { ...payload };
            // On edit, a blank password means "leave unchanged" — strip it so the
            // server's nullable rule does not see an empty string.
            if (mode === 'edit' && payload.password === '') {
                delete next.password;
                delete next.password_confirmation;
                next._method = 'put';
            } else if (mode === 'edit') {
                next._method = 'put';
            }
            return next;
        });
        post(action, { preserveScroll: true });
    };

    const roleOptions = assignableRoles.map((role) => ({
        value: role.value,
        label: t(role.label_key),
    }));

    const organizationOptions = (organizations ?? []).map((organization) => ({
        value: organization.id,
        label: organization.name,
    }));

    return (
        <form onSubmit={submit} noValidate className="grid max-w-3xl gap-6 sm:grid-cols-2">
            <TextField
                label={t('admin.users.field.username')}
                name="username"
                value={data.username}
                onChange={(value) => setData('username', value)}
                error={errors.username}
                required
                maxLength={60}
                autoComplete="username"
                autoCapitalize="none"
                spellCheck={false}
                disabled={disabled}
                autoFocus
            />

            <TextField
                label={t('admin.users.field.name')}
                name="name"
                value={data.name}
                onChange={(value) => setData('name', value)}
                error={errors.name}
                maxLength={100}
                autoComplete="name"
                disabled={disabled}
            />

            <TextField
                label={t('admin.users.field.email')}
                name="email"
                type="email"
                value={data.email}
                onChange={(value) => setData('email', value)}
                error={errors.email}
                maxLength={255}
                autoComplete="email"
                autoCapitalize="none"
                spellCheck={false}
                disabled={disabled}
            />

            <SelectField
                label={t('admin.users.field.role')}
                value={data.role}
                onChange={(value) => setData('role', value)}
                options={roleOptions}
                placeholder={t('admin.users.field.role_placeholder')}
                error={errors.role}
                required
                disabled={disabled}
            />

            {organizations !== null ? (
                <SelectField
                    label={t('admin.users.field.organization')}
                    value={data.organization_id}
                    onChange={(value) =>
                        setData('organization_id', value === '' ? '' : Number(value))
                    }
                    options={organizationOptions}
                    placeholder={t('admin.users.field.organization_placeholder')}
                    error={errors.organization_id}
                    disabled={disabled}
                />
            ) : null}

            <TextField
                label={t('admin.users.field.password')}
                name="password"
                type="password"
                value={data.password}
                onChange={(value) => setData('password', value)}
                error={errors.password}
                required={mode === 'create'}
                autoComplete="new-password"
                minLength={8}
                hint={mode === 'edit' ? t('admin.users.field.password_edit_hint') : undefined}
            />

            <TextField
                label={t('admin.users.field.password_confirmation')}
                name="password_confirmation"
                type="password"
                value={data.password_confirmation}
                onChange={(value) => setData('password_confirmation', value)}
                error={errors.password_confirmation}
                required={mode === 'create'}
                autoComplete="new-password"
                minLength={8}
            />

            <div className="sm:col-span-2">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex h-11 items-center justify-center rounded-lg bg-primary px-6 font-semibold text-white shadow-sm shadow-primary/25 transition-colors hover:bg-primary-dark focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:outline-none disabled:opacity-60"
                >
                    {mode === 'create' ? t('admin.users.submit') : t('admin.users.save')}
                </button>
            </div>
        </form>
    );
}
