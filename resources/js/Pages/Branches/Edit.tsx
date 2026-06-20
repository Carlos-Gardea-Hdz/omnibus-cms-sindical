import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import BranchForm, { type OrganizationOption } from '@/Components/organization/BranchForm';

/**
 * Edit branch screen (SPEC §3.2 ORG-02). role:manager-gated. A cross-org branch
 * (one the confined manager may not see) resolves to 404 server-side via the
 * OrganizationScope — so this page only ever receives a branch the actor owns.
 *
 * Props are snake_case, matching Admin\BranchController::edit EXACTLY:
 *   { branch: { id, name, location, organization_id },
 *     organizations: { id, name }[] }.
 */
interface BranchEditModel {
    id: number;
    name: string;
    location: string;
    organization_id: number;
}

interface EditProps {
    branch: BranchEditModel;
    organizations: OrganizationOption[];
}

export default function BranchesEdit({ branch, organizations }: EditProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('branches.edit.title')}
            backHref="/admin/branches"
            backLabel={t('branches.back_to_list')}
        >
            <BranchForm
                mode="edit"
                action={`/admin/branches/${branch.id}`}
                organizations={organizations}
                initial={{
                    organization_id: branch.organization_id,
                    name: branch.name,
                    location: branch.location,
                }}
            />
        </AdminShell>
    );
}
