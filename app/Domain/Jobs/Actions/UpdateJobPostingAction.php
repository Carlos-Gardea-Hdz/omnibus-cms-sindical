<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Data\CreateJobPostingData;
use App\Domain\Jobs\Models\JobPosting;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Update a JobPosting (SPEC §3.4). Plain CRUD over the writable columns; the lifecycle
 * `status` is NOT touched here — that is the ToggleJobStatusAction's job.
 *
 * SECURITY — tenant write confinement (mirrors UpdateRepresentativeAction): the effective
 * organization_id is resolved SERVER-SIDE from the {@see OrganizationContext}, NOT trusted
 * from the payload — a CONFINED manager/editor cannot move the posting to another tenant
 * via the payload (the resolved org overrides any forged organization_id); only an
 * UNCONFINED admin may re-target the org. The branch_id is then asserted to BELONG to that
 * resolved org (scope-free existence check): neither a confined caller nor an unconfined
 * admin can re-attach the posting to a foreign org's branch. A mismatch is a 302 +
 * branch_id field error, never a cross-org attach.
 */
final class UpdateJobPostingAction
{
    use AssertsBranchBelongsToOrganization;

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(JobPosting $job, CreateJobPostingData $data): JobPosting
    {
        $organizationId = $this->context->isUnconfined()
            ? $data->organization_id
            : $this->context->organizationId();

        $this->assertBranchBelongsToOrganization($data->branch_id, $organizationId);

        return DB::transaction(function () use ($job, $data, $organizationId): JobPosting {
            $job->fill([
                'organization_id' => $organizationId,
                'branch_id' => $data->branch_id,
                'title' => $data->title,
                'description' => $data->description,
                'schedule' => $data->schedule,
                'contact_info' => $data->contact_info,
                'salary_min_cents' => $data->salary_min_cents,
                'salary_max_cents' => $data->salary_max_cents,
                'salary_display' => $data->salary_display,
            ])->save();

            return $job;
        });
    }
}
