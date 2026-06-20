<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Data\CreateJobPostingData;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;

/**
 * Create a JobPosting (SPEC §3.4 JOB-01). A new posting goes live: status = Active.
 * `description` is plain text — NO SanitizesContent (Decision A; React auto-escapes at
 * render).
 *
 * SECURITY — tenant write confinement (mirrors CreateRepresentativeAction / the Article
 * author-stamp pattern): the effective organization_id is resolved SERVER-SIDE from the
 * {@see OrganizationContext}, NOT trusted from the payload — a CONFINED manager/editor can
 * only write into their own org; only an UNCONFINED admin may honour a payload
 * organization_id. The branch_id is then asserted to BELONG to that resolved org (a
 * scope-free existence check): a confined caller cannot attach a posting to another org's
 * branch, and an unconfined admin choosing org X cannot attach a branch from org Y. A
 * foreign branch is a 302 + branch_id field error, never a cross-org attach.
 */
final class CreateJobPostingAction
{
    use AssertsBranchBelongsToOrganization;

    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function handle(CreateJobPostingData $data, User $actor): JobPosting
    {
        $organizationId = $this->context->isUnconfined()
            ? $data->organization_id
            : $this->context->organizationId();

        $this->assertBranchBelongsToOrganization($data->branch_id, $organizationId);

        return DB::transaction(fn (): JobPosting => JobPosting::create([
            'organization_id' => $organizationId,
            'branch_id' => $data->branch_id,
            'created_by' => $actor->getKey(),
            'title' => $data->title,
            'description' => $data->description,
            'schedule' => $data->schedule,
            'contact_info' => $data->contact_info,
            'salary_min_cents' => $data->salary_min_cents,
            'salary_max_cents' => $data->salary_max_cents,
            'salary_display' => $data->salary_display,
            'status' => JobStatus::Active,
        ]));
    }
}
