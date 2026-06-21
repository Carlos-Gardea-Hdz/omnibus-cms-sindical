<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Data\RegisterMemberData;
use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Register a Member (SPEC §3.6 — the PUBLIC anonymous register path, the sole CREATE).
 *
 * Decision F — UNCONFINED by design: unlike the org-scoped Create Actions of slices
 * 003/004 there is NO OrganizationContext org-stamp here. The register path is anonymous,
 * so the applicant's `organization_id` is honoured AS-IS (nullable — a member may register
 * org-less, fail-open is correct because there is no tenant to confine to). The
 * write-isolation crown of this slice is the REVIEW path's cross-org 404, not a create
 * stamp.
 *
 * The PII fields (curp/rfc) are passed PLAIN — the Member model's `encrypted` cast does
 * the encryption at write. A fresh registration is always Pending / is_affiliated=false,
 * stamped server-side here (never from the payload).
 */
final class RegisterMemberAction
{
    public function handle(RegisterMemberData $data): Member
    {
        return DB::transaction(fn (): Member => Member::create([
            'organization_id' => $data->organization_id,
            'municipality_id' => $data->municipality_id,
            'curp' => $data->curp,
            'rfc' => $data->rfc,
            'first_name' => $data->first_name,
            'last_name_paternal' => $data->last_name_paternal,
            'last_name_maternal' => $data->last_name_maternal,
            'date_of_birth' => $data->date_of_birth,
            'address' => $data->address,
            'postal_code' => $data->postal_code,
            'neighborhood' => $data->neighborhood,
            'phone' => $data->phone,
            'mobile' => $data->mobile,
            'is_affiliated' => false,
            'status' => MemberStatus::Pending,
        ]));
    }
}
