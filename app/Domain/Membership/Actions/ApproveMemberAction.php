<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Exceptions\InvalidMemberTransitionException;
use App\Domain\Membership\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Approve a pending Member (SPEC §3.6, §7.3). The MemberStatus graph is the single
 * authoritative guard — approving an already-approved/rejected member is illegal and
 * throws InvalidMemberTransitionException, so the controller never has to know the
 * legality matrix. The exception renders to a graceful 302 + a `status` field error on
 * web (422 JSON) in bootstrap/app.php, never a 500.
 *
 * Approval flips is_affiliated to true (an approved member is now an affiliated union
 * member). The cross-org 404 is enforced upstream by route-model binding under the
 * OrganizationScope — a confined manager can only ever resolve their own org's members.
 */
final class ApproveMemberAction
{
    public function handle(Member $member): Member
    {
        if (! $member->status->canTransitionTo(MemberStatus::Approved)) {
            throw new InvalidMemberTransitionException(__('members.error.invalid_transition'));
        }

        return DB::transaction(function () use ($member): Member {
            $member->update([
                'status' => MemberStatus::Approved,
                'is_affiliated' => true,
            ]);

            return $member;
        });
    }
}
