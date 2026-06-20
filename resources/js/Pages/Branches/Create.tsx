import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import BranchForm, { EMPTY_BRANCH, type OrganizationOption } from '@/Components/organization/BranchForm';

/**
 * Create branch screen (SPEC §3.2 ORG-02). role:manager-gated. The form POSTs to
 * /admin/branches with the BranchData shape.
 *
 * Props are snake_case, matching Admin\BranchController::create EXACTLY:
 *   { organizations: { id, name }[] }.
 * The organization list is server-scoped (a manager only sees their own org).
 */
interface CreateProps {
    organizations: OrganizationOption[];
}

export default function BranchesCreate({ organizations }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('branches.create.title')}
            subtitle={t('branches.create.subtitle')}
            backHref="/admin/branches"
            backLabel={t('branches.back_to_list')}
        >
            <BranchForm
                mode="create"
                action="/admin/branches"
                organizations={organizations}
                initial={EMPTY_BRANCH}
            />
        </AdminShell>
    );
}
