<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Actions;

use App\Domain\Engagement\Data\SubmitContactData;
use App\Domain\Engagement\Models\ContactMessage;
use Illuminate\Support\Facades\DB;

/**
 * Submit a ContactMessage (SPEC §3.5 CONTACT-01, §7.1 — the PUBLIC anonymous submit path,
 * the sole CREATE). The slice-005 public-anonymous-write pattern, adapted to the simplest
 * schema in the CMS.
 *
 * Decision F — UNCONFINED by design: there is NO OrganizationContext org-stamp here. The
 * submit path is anonymous, so the applicant's chosen organization_id is honoured AS-IS
 * (it is NOT NULL — a required public-payload choice). The OrganizationScope only filters
 * SELECT, so it is a no-op on this INSERT — the row lands regardless of any acting context.
 *
 * The WRITE-PROVENANCE GUARD is the branch→org assertion (NOT an org-stamp — there is no
 * owner to stamp): {@see AssertsBranchBelongsToOrganization} runs scope-free and refuses a
 * branch from a DIFFERENT org than the one chosen (a 302 + branch_id field error), so a
 * forged cross-org pair never reaches the DB.
 *
 * NO self-elevation is possible: the table has no `status`/moderation column, so there is
 * nothing for the submitter to forge. Unknown payload keys are discarded by Spatie Data;
 * this Action writes ONLY the defined fields plus a server-assigned id + timestamps. The
 * `message` is PLAIN TEXT (Decision A) — NOT sanitized via SanitizesContent (it is never
 * rendered as HTML; React auto-escapes it).
 */
final class SubmitContactAction
{
    use AssertsBranchBelongsToOrganization;

    public function handle(SubmitContactData $data): ContactMessage
    {
        $this->assertBranchBelongsToOrganization($data->branch_id, $data->organization_id);

        return DB::transaction(fn (): ContactMessage => ContactMessage::create([
            'organization_id' => $data->organization_id,
            'branch_id' => $data->branch_id,
            'first_name' => $data->first_name,
            'last_name' => $data->last_name,
            'email' => $data->email,
            'phone' => $data->phone,
            'message' => $data->message,
        ]));
    }
}
