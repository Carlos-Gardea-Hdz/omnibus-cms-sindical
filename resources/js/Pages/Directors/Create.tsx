import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DirectorForm, {
    EMPTY_DIRECTOR,
    type OrganizationOption,
} from '@/Components/organization/DirectorForm';

/**
 * Create director screen (SPEC §3.2 ORG-04). role:administrator-gated. The form
 * POSTs (multipart) to /admin/directors with the DirectorData shape. The
 * one-director-per-org rule is enforced server-side.
 *
 * Props are snake_case, matching Admin\DirectorController::create EXACTLY:
 *   { organizations: { id, name }[] }.
 */
interface CreateProps {
    organizations: OrganizationOption[];
}

export default function DirectorsCreate({ organizations }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('directors.create.title')}
            subtitle={t('directors.create.subtitle')}
            backHref="/admin/directors"
            backLabel={t('directors.back_to_list')}
        >
            <DirectorForm
                mode="create"
                action="/admin/directors"
                organizations={organizations}
                initial={EMPTY_DIRECTOR}
            />
        </AdminShell>
    );
}
