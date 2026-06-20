import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import OrganizationForm, {
    EMPTY_ORGANIZATION,
    type MunicipalityOption,
} from '@/Components/organization/OrganizationForm';

/**
 * Create organization screen (SPEC §3.2 ORG-01). role:super_admin-gated. The logo
 * is required on create (OrganizationData::rules); the form POSTs (multipart) to
 * /admin/organizations with the OrganizationData shape.
 *
 * Props are snake_case, matching Admin\OrganizationController::create EXACTLY:
 *   { municipalities: { id, name }[] }.
 */
interface CreateProps {
    municipalities: MunicipalityOption[];
}

export default function OrganizationsCreate({ municipalities }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('organizations.create.title')}
            subtitle={t('organizations.create.subtitle')}
            backHref="/admin/organizations"
            backLabel={t('organizations.back_to_list')}
        >
            <OrganizationForm
                mode="create"
                action="/admin/organizations"
                municipalities={municipalities}
                initial={EMPTY_ORGANIZATION}
            />
        </AdminShell>
    );
}
