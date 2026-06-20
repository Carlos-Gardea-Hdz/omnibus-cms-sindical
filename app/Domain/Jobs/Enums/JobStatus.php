<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * JobPosting lifecycle status (SPEC §3.4 JOB-02). The enum is the single source of
 * truth for legal transitions — the ToggleJobStatusAction calls canTransitionTo()
 * before mutating, so the controller never knows the legality matrix. Mirrors
 * ArticleStatus.
 *
 * Transition graph (Decision B — a superset of the JOB-02 linear path, adding the
 * reactivate edge paused→active):
 *   draft    → active, closed
 *   active   → paused, closed
 *   paused   → active, closed
 *   closed   →             (terminal)
 * Everything else — including any self→self — is illegal.
 *
 * Default on create is Active (JOB-01): a new posting goes live immediately.
 */
#[TypeScript]
enum JobStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';

    /**
     * The states this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Closed],
            self::Active => [self::Paused, self::Closed],
            self::Paused => [self::Active, self::Closed],
            self::Closed => [],
        };
    }

    /** Guard for the lifecycle — the authoritative legality check. */
    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions(), strict: true);
    }

    /** i18n key resolved client-side and via __() server-side: job_status.draft, etc. */
    public function labelKey(): string
    {
        return 'job_status.'.$this->value;
    }

    /** Magenta-palette accent per status (SPEC §1.4) for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#A03CC7',   // neutral accent
            self::Active => '#DD00FF',  // primary
            self::Paused => '#E647FF',  // primary-light
            self::Closed => '#9211CF',  // primary-dark
        };
    }
}
