<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\UserRole;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request organization-scoping switch (SPEC §10.3, slice-003 §3 / Deviation D).
 * It binds the acting user's role to the request-scoped {@see OrganizationContext},
 * which the {@see \App\Support\OrganizationScope} global scope reads to filter the
 * org-owned models (Article, Branch, Director, Representative).
 *
 * Role policy: manager + editor (level ≤ {@see UserRole::Manager}) are CONFINED to
 * their own `organization_id`; administrator + super_admin are UNCONFINED (genuine
 * cross-org). A confined manager whose `organization_id` is null fails closed in the
 * scope (`WHERE 1 = 0`) — that decision lives in the scope, not here.
 *
 * DEMO ISOLATION (the load-bearing override): a demo session is NEVER cross-org — its
 * whole purpose is to showcase the admin UI on the single seeded showcase org. So a
 * demo session is CONFINED to its showcase org REGARDLESS of role, even when the preset
 * is `administrator`. Without this, a demo administrator would unconfine and the global
 * {@see \App\Support\OrganizationScope} would expose every real org's rows + PII (the
 * member review inbox, the contact inbox). The demo flag is read straight off the
 * session ({@see \App\Http\Controllers\Auth\DemoLoginController} stamps `is_demo`), and
 * the org is the demo user's own `organization_id` (the showcase org it was minted in).
 * This runs BEFORE {@see DemoSessionMiddleware} (which only sets the cleanup tag + blocks
 * writes), so the confinement is in place for the whole request.
 *
 * This middleware NEVER aborts: it filters data, it does not authorize. The `role`
 * alias owns access (403s); this one only sets context and returns `$next($request)`.
 * It imports {@see UserRole} from the Identity domain — legitimate in HTTP middleware,
 * exactly as {@see EnsureRole} already does (this is not domain code).
 */
final class EnsureOrganizationScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = app(OrganizationContext::class);
        $role = $user?->role;

        // A demo visitor is NEVER cross-org: confine to its showcase org regardless of
        // the preset's role (even `administrator`). This is the demo-isolation firewall.
        if ($user !== null && $request->session()->get('is_demo') === true) {
            $context->confineTo($user->organization_id);

            return $next($request);
        }

        if ($role instanceof UserRole && $role->level() <= UserRole::Manager->level()) {
            $context->confineTo($user->organization_id);
        } else {
            $context->unconfine();
        }

        return $next($request);
    }
}
