import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import JobForm, {
    EMPTY_JOB,
    type BranchOption,
    type OrganizationOption,
} from '@/Components/jobs/JobForm';

/**
 * Create job posting screen (slice 004, SPEC §3.4 JOB-01). role:editor-gated. The
 * form POSTs to /admin/jobs with the CreateJobPostingData shape; the created job
 * is always JobStatus::Active (server-stamped, JOB-01).
 *
 * Props are snake_case, matching Admin\JobController::create EXACTLY:
 *   { branch_options: { id, name, organization_id }[],
 *     organization_options?: { id, name }[] }   // admin only.
 * A confined manager/editor receives no `organization_options` (their org is
 * server-stamped); an unconfined admin gets the org list and the form shows the
 * organization picker. Both lists are server-scoped to what the actor may see.
 */
interface CreateProps {
    branch_options: BranchOption[];
    organization_options?: OrganizationOption[];
}

export default function JobsCreate({ branch_options, organization_options }: CreateProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('jobs.create.title')}
            subtitle={t('jobs.create.subtitle')}
            backHref="/admin/jobs"
            backLabel={t('jobs.back_to_list')}
        >
            <JobForm
                mode="create"
                action="/admin/jobs"
                organizations={organization_options}
                branches={branch_options}
                initial={EMPTY_JOB}
            />
        </AdminShell>
    );
}
