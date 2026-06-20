import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import OrganizationForm, {
    type MunicipalityOption,
} from '@/Components/organization/OrganizationForm';

/**
 * Edit organization screen (SPEC §3.2 ORG-01). role:super_admin-gated. Logo
 * replacement is optional (only a freshly-picked file replaces the stored one).
 *
 * Props are snake_case, matching Admin\OrganizationController::edit EXACTLY:
 *   { organization: { id, name, slug, municipality_id, registered_at,
 *     logo_url }, municipalities: { id, name }[] }.
 *
 * The form submits as POST + _method=put so the multipart logo upload survives.
 */
interface OrganizationEditModel {
    id: number;
    name: string;
    slug: string;
    municipality_id: number;
    registered_at: string;
    logo_url: string | null;
}

interface EditProps {
    organization: OrganizationEditModel;
    municipalities: MunicipalityOption[];
}

export default function OrganizationsEdit({ organization, municipalities }: EditProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('organizations.edit.title')}
            backHref="/admin/organizations"
            backLabel={t('organizations.back_to_list')}
        >
            <OrganizationForm
                mode="edit"
                action={`/admin/organizations/${organization.id}`}
                municipalities={municipalities}
                initial={{
                    name: organization.name,
                    slug: organization.slug,
                    municipality_id: organization.municipality_id,
                    registered_at: organization.registered_at,
                    logo_url: organization.logo_url,
                }}
            />
        </AdminShell>
    );
}
