<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Membership\Actions\RegisterMemberAction;
use App\Domain\Membership\Data\RegisterMemberData;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public union-membership registration (SPEC §3.6 MEMBER-01, §7.3, §10.5 / slice-005 §8).
 * This is the SOLE create path for a {@see \App\Domain\Membership\Models\Member}: an
 * ANONYMOUS, fully UNCONFINED endpoint — no `org.scope`, no `auth` (routes/web.php sits it
 * at the top level under `throttle:5,60`). A visitor submits their own PII and chooses
 * their municipality (RESTRICT FK, always required) and, optionally, an organization
 * (nullable SET-NULL FK). The new member always lands {@see \App\Domain\Membership\Enums\MemberStatus::Pending}
 * with `is_affiliated = false` — the Action stamps those, never the payload.
 *
 * Anemic by law (≤15 lines/method): {@see store} hands a validated {@see RegisterMemberData}
 * (resolved via the method signature → a bad field is 302 + session errors, NEVER 422) to
 * {@see RegisterMemberAction}, which owns the write and the `encrypted`-cast PII at rest
 * (curp / rfc) inside a `DB::transaction`. The DTO's `organization_id` is honoured AS-IS
 * here (there is no confined caller to constrain — registration is public), unlike the
 * org-stamped admin writes in the rest of the CMS.
 *
 * Both option lists are unconfined: the request carries no session confinement (the
 * anonymous visitor), so the {@see \App\Support\OrganizationScope} resets to UNCONFINED and
 * the organization picker shows every org; municipality is a shared catalog (never
 * org-scoped). No member PII is ever READ back on a public path — only the option
 * catalogs are exposed (SPEC §10.5: member data is admin-only).
 */
final class MemberController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Membership/Register', [
            'municipality_options' => $this->municipalityOptions(),
            'organization_options' => $this->organizationOptions(),
        ]);
    }

    public function store(RegisterMemberData $data, RegisterMemberAction $action): RedirectResponse
    {
        $action->handle($data);

        return redirect()->route('membership.create')->with('success', __('membership.registered'));
    }

    /**
     * The municipality select options for the public form (id + name). A shared catalog
     * — never org-scoped — so every municipality is offered, ordered by name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function municipalityOptions(): array
    {
        return array_values(
            Municipality::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Municipality $municipality): array => [
                    'id' => $municipality->id,
                    'name' => $municipality->name,
                ])->all()
        );
    }

    /**
     * The organization select options for the public form (id + name). Unconfined: the
     * anonymous request never sets a session confinement, so every org is offered.
     *
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        return array_values(
            Organization::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ])->all()
        );
    }
}
