<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Level-based RBAC gate (SPEC §3.1 AUTH-03, §10.2). Each route declares ONE minimum
 * level: `->middleware('role:editor')`. The user's UserRole passes when it meets or
 * exceeds that level (a higher role satisfies a lower gate). Authorization lives in
 * HTTP middleware, never the domain. Guests are caught upstream by `auth` (→302 login);
 * a wrong-LEVEL authenticated user is a 403.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string $minimum): Response
    {
        $role = $request->user()?->role;
        $required = UserRole::from($minimum);

        if (! $role instanceof UserRole || ! $role->hasAtLeast($required)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
