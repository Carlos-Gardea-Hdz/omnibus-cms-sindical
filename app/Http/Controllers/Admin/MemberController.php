<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Membership\Actions\ApproveMemberAction;
use App\Domain\Membership\Actions\RejectMemberAction;
use App\Domain\Membership\Enums\MemberStatus;
use App\Domain\Membership\Models\Member;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin membership review (SPEC §3.6 MEMBER-02, §7.3, §10.2, §10.5 / slice-005 §8). Member is
 * org-scoped: the review listing is auto-filtered by the {@see \App\Support\OrganizationScope}
 * global scope under the `org.scope` middleware — a confined manager sees ONLY their own org's
 * members (no manual `where`), and a cross-org route-model-bound {member} is unresolvable → 404.
 * That 404 IS the write-isolation crown of this slice: there is no server-side org-stamp to do
 * (no create/update here), so a confined manager of org A approving/rejecting an org-B member
 * simply cannot resolve the row — route binding (under `org.scope`, which runs BEFORE
 * SubstituteBindings) hands them a 404, never another org's member. Gated `role:manager` upstream
 * in routes/web.php (an editor — a LOWER rung — is a 403; members are not editor-accessible, §10.2).
 *
 * Anemic by law (≤15 lines/method): {@see approve}/{@see reject} hand the route-bound, already
 * org-resolved {@see Member} to its Action, which owns the {@see MemberStatus} transition guard
 * (an illegal edge throws {@see \App\Domain\Membership\Exceptions\InvalidMemberTransitionException}
 * → a graceful 302 + `status` error via bootstrap/app.php, never a 500) and the `is_affiliated`
 * flip on approval. There is NO admin create / update / delete / edit (SPEC §7.3 gives admin only
 * index + approve + reject); the SOLE create path is the public registration endpoint.
 *
 * PII boundary (SPEC §10.5, Decision E): the index read model NEVER exposes curp / rfc — only a
 * composed full_name, the municipality name, the status, the affiliation flag and the timestamp.
 * The encrypted PII columns stay at rest, out of the prop contract. The actor is never read via
 * `Illuminate\Http\Request` (controller arch law); the index status filter uses the `request()`
 * helper only. The status filter is validated against {@see MemberStatus} (the enum SSOT) via
 * tryFrom: a junk ?status is IGNORED (treated as no filter), never a raw string compared against
 * the column (which would silently return an empty set).
 *
 * ORG ISOLATION (defense-in-depth, mirrors AnalyticsController/UserController): the review index
 * ALSO applies an EXPLICIT own-org `where` for any non-super_admin actor. A manager is already
 * confined by the global scope (a no-op for it); an administrator — own-org by design (§10.2 RBAC
 * matrix) — would otherwise run UNCONFINED and read every org's member PII, so the explicit filter
 * pins it to its own org. A super_admin alone sees every org's members.
 */
final class MemberController extends Controller
{
    public function index(): Response
    {
        // The status filter is validated against MemberStatus (the SSOT): an out-of-enum
        // ?status is IGNORED (tryFrom → null), so a junk value behaves as "no filter"
        // rather than silently producing an empty result set on a raw string compare.
        $status = MemberStatus::tryFrom(request()->string('status')->toString());

        $actor = request()->user();
        $ownOrgId = $actor instanceof User && $actor->role !== UserRole::SuperAdmin
            ? $actor->organization_id
            : null;

        $members = Member::query()
            ->when($ownOrgId !== null, fn ($query) => $query->where('organization_id', $ownOrgId))
            ->with(['municipality:id,name'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Member $member): array => $this->mapRow($member));

        return Inertia::render('Admin/Members/Index', [
            'members' => [
                'data' => $members->items(),
                'links' => $members->linkCollection()->toArray(),
                'meta' => [
                    'from' => $members->firstItem(),
                    'to' => $members->lastItem(),
                    'total' => $members->total(),
                ],
            ],
            'statuses' => $this->statusOptions(),
            'filters' => ['status' => $status?->value],
        ]);
    }

    public function approve(Member $member, ApproveMemberAction $action): RedirectResponse
    {
        $action->handle($member);

        return back()->with('success', __('members.approved'));
    }

    public function reject(Member $member, RejectMemberAction $action): RedirectResponse
    {
        $action->handle($member);

        return back()->with('success', __('members.rejected'));
    }

    /**
     * Shape one paginated member row for the admin review index (snake_case prop contract).
     * NO curp / rfc — the encrypted PII columns are never exposed in a read model (SPEC §10.5).
     *
     * @return array<string, mixed>
     */
    private function mapRow(Member $member): array
    {
        // municipality is a NOT NULL restrict FK (eager-loaded above).
        return [
            'id' => $member->id,
            'full_name' => trim("{$member->first_name} {$member->last_name_paternal} {$member->last_name_maternal}"),
            'municipality_name' => $member->municipality->name,
            'status' => $member->status->value,
            'status_label_key' => $member->status->labelKey(),
            'is_affiliated' => $member->is_affiliated,
            // created_at is non-nullable (timestamps always set) — no nullsafe needed (L9).
            'created_at' => $member->created_at->toIso8601String(),
        ];
    }

    /**
     * The MemberStatus select options for the index filter (value + i18n key).
     *
     * @return list<array{value: string, label_key: string}>
     */
    private function statusOptions(): array
    {
        return array_values(array_map(
            static fn (MemberStatus $status): array => [
                'value' => $status->value,
                'label_key' => $status->labelKey(),
            ],
            MemberStatus::cases(),
        ));
    }
}
