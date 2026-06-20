import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DirectorForm, { type OrganizationOption } from '@/Components/organization/DirectorForm';

/**
 * Edit director screen (SPEC §3.2 ORG-04). role:administrator-gated. Photo
 * replacement is optional (only a freshly-picked file replaces the stored one).
 *
 * Props are snake_case, matching Admin\DirectorController::edit EXACTLY:
 *   { director: { id, first_name, last_name, organization_id, photo_url },
 *     organizations: { id, name }[] }.
 *
 * The form submits as POST + _method=put so the multipart photo upload survives.
 */
interface DirectorEditModel {
    id: number;
    first_name: string;
    last_name: string;
    organization_id: number;
    photo_url: string | null;
}

interface EditProps {
    director: DirectorEditModel;
    organizations: OrganizationOption[];
}

export default function DirectorsEdit({ director, organizations }: EditProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('directors.edit.title')}
            backHref="/admin/directors"
            backLabel={t('directors.back_to_list')}
        >
            <DirectorForm
                mode="edit"
                action={`/admin/directors/${director.id}`}
                organizations={organizations}
                initial={{
                    organization_id: director.organization_id,
                    first_name: director.first_name,
                    last_name: director.last_name,
                    photo_url: director.photo_url,
                }}
            />
        </AdminShell>
    );
}
