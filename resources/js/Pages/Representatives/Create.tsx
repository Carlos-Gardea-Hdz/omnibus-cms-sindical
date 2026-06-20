import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import RepresentativeForm, {
    EMPTY_REPRESENTATIVE,
    type BranchOption,
    type OrganizationOption,
} from '@/Components/organization/RepresentativeForm';

/**
 * Create representative screen (SPEC §3.2 ORG-03). role:manager-gated. The form
 * POSTs (multipart) to /admin/representatives with the RepresentativeData shape.
 *
 * Props are snake_case, matching Admin\RepresentativeController::create EXACTLY:
 *   { organizations: { id, name }[],
 *     branches: { id, name, organization_id }[] }.
 * Both lists are server-scoped (a manager only sees their own org + its
 * branches); the branch select filters client-side by the chosen organization.
 */
interface CreateProps {
    organizations: OrganizationOption[];
    branches: BranchOption[];
}

export default function RepresentativesCreate({ organizations, branches }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('representatives.create.title')}
            subtitle={t('representatives.create.subtitle')}
            backHref="/admin/representatives"
            backLabel={t('representatives.back_to_list')}
        >
            <RepresentativeForm
                mode="create"
                action="/admin/representatives"
                organizations={organizations}
                branches={branches}
                initial={EMPTY_REPRESENTATIVE}
            />
        </AdminShell>
    );
}
