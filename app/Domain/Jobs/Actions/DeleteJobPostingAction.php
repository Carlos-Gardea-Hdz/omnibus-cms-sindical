<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Models\JobPosting;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a JobPosting (SPEC §3.4 / §7.2 "Soft delete"). job_postings is a leaf
 * entity — nothing FKs to it — so there is NO restrict-delete pre-check to run; the
 * delete is graceful by construction. The bound model is already org-scoped (a confined
 * manager could only have resolved its own org's posting), so deletion is tenant-safe.
 */
final class DeleteJobPostingAction
{
    public function handle(JobPosting $job): void
    {
        DB::transaction(static fn (): ?bool => $job->delete());
    }
}
