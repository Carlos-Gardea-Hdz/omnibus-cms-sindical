<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Data;

use App\Domain\Jobs\Enums\JobStatus;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * JobPosting status-toggle payload (SPEC §3.4 JOB-02). Carries only the TARGET status.
 *
 * Spatie Data is the single source of truth: `status` resolves to the JobStatus backed
 * enum, so a bad value is a 302 validation error (enum coercion), never a magic string
 * reaching the Action. The legality of the edge (canTransitionTo) is NOT validation —
 * it is the ToggleJobStatusAction's job, which throws InvalidJobTransitionException for
 * an illegal pair (rendered 302 + a `status` field error on web / 422 JSON).
 */
#[TypeScript]
final class ToggleJobStatusData extends Data
{
    public function __construct(
        #[Required]
        public JobStatus $status,
    ) {}
}
