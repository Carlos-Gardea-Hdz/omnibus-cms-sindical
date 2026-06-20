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

        if ($role instanceof UserRole && $role->level() <= UserRole::Manager->level()) {
            $context->confineTo($user->organization_id);
        } else {
            $context->unconfine();
        }

        return $next($request);
    }
}
