import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import RepresentativeForm, {
    type BranchOption,
    type OrganizationOption,
    type ShiftValue,
} from '@/Components/organization/RepresentativeForm';

/**
 * Edit representative screen (SPEC §3.2 ORG-03). role:manager-gated. Photo
 * replacement is optional (only a freshly-picked file replaces the stored one). A
 * cross-org representative resolves to 404 server-side via the OrganizationScope,
 * so this page only ever receives one the actor owns.
 *
 * Props are snake_case, matching Admin\RepresentativeController::edit EXACTLY:
 *   { representative: { id, first_name, last_name, organization_id, branch_id,
 *     shift, is_coordinator, photo_url },
 *     organizations: { id, name }[],
 *     branches: { id, name, organization_id }[] }.
 *
 * The form submits as POST + _method=put so the multipart photo upload survives.
 */
interface RepresentativeEditModel {
    id: number;
    first_name: string;
    last_name: string;
    organization_id: number;
    branch_id: number;
    shift: ShiftValue;
    is_coordinator: boolean;
    photo_url: string | null;
}

interface EditProps {
    representative: RepresentativeEditModel;
    organizations: OrganizationOption[];
    branches: BranchOption[];
}

export default function RepresentativesEdit({
    representative,
    organizations,
    branches,
}: EditProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('representatives.edit.title')}
            backHref="/admin/representatives"
            backLabel={t('representatives.back_to_list')}
        >
            <RepresentativeForm
                mode="edit"
                action={`/admin/representatives/${representative.id}`}
                organizations={organizations}
                branches={branches}
                initial={{
                    organization_id: representative.organization_id,
                    branch_id: representative.branch_id,
                    first_name: representative.first_name,
                    last_name: representative.last_name,
                    shift: representative.shift,
                    is_coordinator: representative.is_coordinator,
                    photo_url: representative.photo_url,
                }}
            />
        </AdminShell>
    );
}
