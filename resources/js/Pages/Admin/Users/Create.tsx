import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DemoBanner from '@/Components/demo/DemoBanner';
import UserForm, {
    EMPTY_USER,
    type OrganizationOption,
    type RoleOption,
} from '@/Components/identity/UserForm';

/**
 * Create user screen (CMS slice 008, AUTH-04). role:administrator-gated. A password
 * is required + confirmed here; the form POSTs the UserData shape to /admin/users.
 *
 * Props are snake_case, matching Admin\UserController::create EXACTLY:
 *   {
 *     assignable_roles: Array<{ value, label_key }>,   // admin's list excludes super_admin
 *     organizations: Array<{ id, name }> | null        // super_admin only; null hides the field
 *   }
 *
 * An administrator's `assignable_roles` cannot contain super_admin (no-self-elevation /
 * the singleton invariant) and `organizations` is null (the org is stamped from the
 * actor's context server-side, never from this payload). For a super_admin both are
 * populated for a cross-org create.
 */
interface CreateProps {
    assignable_roles: RoleOption[];
    organizations: OrganizationOption[] | null;
}

export default function UsersCreate({ assignable_roles, organizations }: CreateProps) {
    const { t } = useLocale();

    return (
        <>
            <DemoBanner />
            <AdminShell
                title={t('admin.users.new')}
                subtitle={t('admin.users.create.subtitle')}
                backHref="/admin/users"
                backLabel={t('admin.users.back_to_list')}
            >
                <UserForm
                    mode="create"
                    action="/admin/users"
                    assignableRoles={assignable_roles}
                    organizations={organizations}
                    initial={EMPTY_USER}
                />
            </AdminShell>
        </>
    );
}
