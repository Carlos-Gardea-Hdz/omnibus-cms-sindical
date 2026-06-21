import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DemoBanner from '@/Components/demo/DemoBanner';
import UserForm, {
    type OrganizationOption,
    type RoleOption,
} from '@/Components/identity/UserForm';

/**
 * Edit user screen (CMS slice 008, AUTH-04). role:administrator-gated. A blank
 * password leaves the stored hash unchanged; a typed password (confirmed) replaces
 * it. The form submits as POST + _method=put to /admin/users/{id}.
 *
 * Props are snake_case, matching Admin\UserController::edit EXACTLY:
 *   {
 *     user: { id, username, name, email, role, organization_id, is_demo },
 *     assignable_roles: Array<{ value, label_key }>,
 *     organizations: Array<{ id, name }> | null,
 *     is_self: boolean,
 *     is_only_super_admin: boolean
 *   }
 * No password / remember_token ever rides in `user`.
 *
 * A cross-org target 404s server-side for an administrator (the User model has no
 * global scope — the controller binds with an explicit scoped lookup), so this page
 * only ever renders a target the actor may legally edit. `is_self` /
 * `is_only_super_admin` drive informational notes (the sole super_admin can only
 * be edited by itself and cannot self-demote — AUTH-04 super_admin_self_only; the
 * server re-checks and an illegal change → 302 + a field error).
 */
interface UserEditModel {
    id: number;
    username: string;
    name: string | null;
    email: string | null;
    role: string;
    organization_id: number | null;
    is_demo: boolean;
}

interface EditProps {
    user: UserEditModel;
    assignable_roles: RoleOption[];
    organizations: OrganizationOption[] | null;
    is_self: boolean;
    is_only_super_admin: boolean;
}

export default function UsersEdit({
    user,
    assignable_roles,
    organizations,
    is_self,
    is_only_super_admin,
}: EditProps) {
    const { t } = useLocale();

    const note = is_only_super_admin
        ? t('admin.users.note.only_super_admin')
        : is_self
          ? t('admin.users.note.self')
          : null;

    return (
        <>
            <DemoBanner />
            <AdminShell
                title={t('admin.users.edit_title')}
                subtitle={user.username}
                backHref="/admin/users"
                backLabel={t('admin.users.back_to_list')}
            >
                {note ? (
                    <p className="mb-6 rounded-lg border border-primary/30 bg-primary-50 px-4 py-3 text-sm font-medium text-primary-dark dark:bg-primary/10 dark:text-primary-light">
                        {note}
                    </p>
                ) : null}

                <UserForm
                    mode="edit"
                    action={`/admin/users/${user.id}`}
                    assignableRoles={assignable_roles}
                    organizations={organizations}
                    initial={{
                        username: user.username,
                        name: user.name ?? '',
                        email: user.email ?? '',
                        role: user.role,
                        organization_id: user.organization_id ?? '',
                    }}
                />
            </AdminShell>
        </>
    );
}
