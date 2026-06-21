<?php

declare(strict_types=1);

namespace App\Domain\Membership\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Member lifecycle status (SPEC §3.6, Decision C). The enum is the single source of
 * truth for legal transitions — the Approve/Reject Member Actions call canTransitionTo()
 * before mutating, so the controller never knows the legality matrix. Mirrors JobStatus.
 *
 * Transition graph (Decision C — a register-then-review lifecycle, both review outcomes
 * terminal):
 *   pending  → approved, rejected
 *   approved →                        (terminal)
 *   rejected →                        (terminal)
 * Everything else — including any self→self — is illegal.
 *
 * Default on register is Pending: a public registration awaits org-admin review.
 */
#[TypeScript]
enum MemberStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * The states this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [],
            self::Rejected => [],
        };
    }

    /** Guard for the lifecycle — the authoritative legality check. */
    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions(), strict: true);
    }

    /** i18n key resolved client-side and via __() server-side: member_status.pending, etc. */
    public function labelKey(): string
    {
        return 'member_status.'.$this->value;
    }

    /** Magenta-palette accent per status (SPEC §1.4) for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => '#A03CC7',   // neutral accent
            self::Approved => '#DD00FF',  // primary
            self::Rejected => '#9211CF',  // primary-dark
        };
    }
}
