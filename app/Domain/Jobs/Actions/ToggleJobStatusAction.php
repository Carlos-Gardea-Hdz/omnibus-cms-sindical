<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Actions;

use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Exceptions\InvalidJobTransitionException;
use App\Domain\Jobs\Models\JobPosting;
use Illuminate\Support\Facades\DB;

/**
 * Toggle a JobPosting's lifecycle status (SPEC §3.4 JOB-02). The JobStatus graph is the
 * single authoritative guard — any illegal pair (including a self→self) throws
 * InvalidJobTransitionException, so the controller never has to know the legality matrix.
 * The exception renders to a graceful 302 + a `status` field error on web (422 JSON) in
 * bootstrap/app.php, never a 500.
 */
final class ToggleJobStatusAction
{
    public function handle(JobPosting $job, JobStatus $target): JobPosting
    {
        if (! $job->status->canTransitionTo($target)) {
            throw new InvalidJobTransitionException(__('jobs.error.invalid_transition'));
        }

        return DB::transaction(function () use ($job, $target): JobPosting {
            $job->update(['status' => $target]);

            return $job;
        });
    }
}
