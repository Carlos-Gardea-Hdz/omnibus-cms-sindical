<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Exceptions\InvalidMemberTransitionException;
use App\Domain\Membership\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Reject a pending Member (SPEC §3.6, §7.3). The MemberStatus graph is the single
 * authoritative guard — rejecting an already-approved/rejected member is illegal and
 * throws InvalidMemberTransitionException (a graceful 302 + `status` field error on web,
 * 422 JSON, never a 500).
 *
 * Rejection leaves is_affiliated false (a rejected applicant never becomes affiliated).
 * The cross-org 404 is enforced upstream by route-model binding under the
 * OrganizationScope — a confined manager can only ever resolve their own org's members.
 */
final class RejectMemberAction
{
    public function handle(Member $member): Member
    {
        if (! $member->status->canTransitionTo(MemberStatus::Rejected)) {
            throw new InvalidMemberTransitionException(__('members.error.invalid_transition'));
        }

        return DB::transaction(function () use ($member): Member {
            $member->update(['status' => MemberStatus::Rejected]);

            return $member;
        });
    }
}
