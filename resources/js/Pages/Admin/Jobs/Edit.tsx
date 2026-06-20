import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import JobForm, { type BranchOption, type OrganizationOption } from '@/Components/jobs/JobForm';

/**
 * Edit job posting screen (slice 004, SPEC §3.4 JOB-01). role:editor-gated. A
 * cross-org job resolves to 404 server-side via the OrganizationScope, so this
 * page only ever receives one the actor owns. The form PUTs to /admin/jobs/{id}
 * with the CreateJobPostingData shape; `status` is NOT edited here (the index
 * lifecycle toggle owns transitions), and a confined manager's payload
 * organization_id is ignored server-side — they cannot move a job between orgs.
 *
 * Props are snake_case, matching Admin\JobController::edit EXACTLY:
 *   { job: { id, title, description, schedule, contact_info, branch_id,
 *     organization_id, salary_min_cents, salary_max_cents, salary_display,
 *     status },
 *     branch_options: { id, name, organization_id }[],
 *     organization_options?: { id, name }[],   // admin only
 *     statuses: { value, label_key }[] }.
 *
 * `statuses` arrives for symmetry with the controller contract (the index owns
 * the lifecycle UI); the edit form intentionally omits a status control so the
 * only path to a transition is the guarded toggle.
 */
interface JobEditModel {
    id: number;
    title: string;
    description: string;
    schedule: string;
    contact_info: string;
    branch_id: number;
    organization_id: number;
    salary_min_cents: number | null;
    salary_max_cents: number | null;
    salary_display: string | null;
    status: string;
}

interface EditProps {
    job: JobEditModel;
    branch_options: BranchOption[];
    organization_options?: OrganizationOption[];
}

export default function JobsEdit({ job, branch_options, organization_options }: EditProps) {
    const { t } = useLocale();

    return (
        <AdminShell
            title={t('jobs.edit.title')}
            backHref="/admin/jobs"
            backLabel={t('jobs.back_to_list')}
        >
            <JobForm
                mode="edit"
                action={`/admin/jobs/${job.id}`}
                organizations={organization_options}
                branches={branch_options}
                initial={{
                    organization_id: job.organization_id,
                    branch_id: job.branch_id,
                    title: job.title,
                    description: job.description,
                    schedule: job.schedule,
                    contact_info: job.contact_info,
                    salary_min_cents: job.salary_min_cents ?? '',
                    salary_max_cents: job.salary_max_cents ?? '',
                    salary_display: job.salary_display ?? '',
                }}
            />
        </AdminShell>
    );
}
