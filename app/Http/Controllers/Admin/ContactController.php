<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Identity\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin contact inbox (SPEC §3.5, §6.3.11, §7.2, §10.2, §10.5 / slice-006 §6). ContactMessage is
 * org-scoped: the inbox listing is auto-filtered by the {@see \App\Support\OrganizationScope}
 * global scope under the `org.scope` middleware — a confined manager sees ONLY their own org's
 * messages (no manual `where`), an unconfined administrator/super_admin sees every org. Gated
 * `role:manager` upstream in routes/web.php (an editor — a LOWER rung — is a 403; Contacts are
 * not editor-accessible, §10.2).
 *
 * Anemic + READ-ONLY by law: there is NO create / update / delete here. A contact message is a
 * permanent audit record (§6.4) — the `contact_messages` table has no `status` and no
 * `deleted_at`, so there is no moderation lifecycle, no approve/reject, no delete route. The
 * SOLE create path is the public submission endpoint ({@see \App\Http\Controllers\Public\ContactController}).
 *
 * PII boundary (SPEC §10.5): the inbox read model DOES surface email + phone — replying to a
 * message is its whole purpose, and the data is org-scoped to the manager's own organization,
 * so this is a deliberate authorized read, not a leak. The plain-text `message` is rendered as
 * escaped text in React (never `dangerouslySetInnerHTML`). The actor is never read via
 * `Illuminate\Http\Request` (controller arch law); the resolution uses the `request()` helper only.
 *
 * ORG ISOLATION (defense-in-depth, mirrors AnalyticsController/UserController): only a super_admin
 * is cross-org and may narrow via the optional `?organization_id=` filter (null = every org). A
 * non-super_admin administrator — own-org by design (§10.2 RBAC matrix) — is PINNED to its own org
 * (it cannot override the confinement via the query string), or it would otherwise run UNCONFINED
 * and read every org's contact PII (email/phone). A manager is already confined by the global
 * scope, so pinning its own org is a harmless no-op.
 */
final class ContactController extends Controller
{
    public function index(): Response
    {
        $actor = request()->user();
        $isSuperAdmin = $actor instanceof User && $actor->role === UserRole::SuperAdmin;

        // A non-super_admin administrator is PINNED to its own org (it cannot override the
        // confinement via ?organization_id); a super_admin may narrow to one org via the
        // optional filter (null = every org). A manager is already confined by the global
        // scope, so its own-org id is a harmless no-op narrowing.
        $organizationId = $isSuperAdmin
            ? (request()->integer('organization_id') ?: null)
            : ($actor instanceof User ? $actor->organization_id : null);

        $messages = ContactMessage::query()
            ->with(['branch:id,name'])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ContactMessage $message): array => $this->mapRow($message));

        return Inertia::render('Admin/Contacts/Index', [
            'messages' => [
                'data' => $messages->items(),
                'links' => $messages->linkCollection()->toArray(),
                'meta' => [
                    'from' => $messages->firstItem(),
                    'to' => $messages->lastItem(),
                    'total' => $messages->total(),
                ],
            ],
            'filters' => ['organization_id' => $organizationId],
        ]);
    }

    /**
     * Shape one paginated contact message for the admin inbox (snake_case prop contract).
     * email + phone ARE exposed — the message exists to be replied to, scoped to the org.
     *
     * @return array<string, mixed>
     */
    private function mapRow(ContactMessage $message): array
    {
        // branch is a NOT NULL restrict FK (eager-loaded above).
        return [
            'id' => $message->id,
            'full_name' => trim("{$message->first_name} {$message->last_name}"),
            'email' => $message->email,
            'phone' => $message->phone,
            'branch_name' => $message->branch->name,
            'message' => $message->message,
            // created_at is non-nullable (timestamps always set) — no nullsafe needed (L9).
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }
}
