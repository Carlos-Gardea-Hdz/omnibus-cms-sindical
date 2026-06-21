<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\DemoLoginController;
use App\Support\DemoContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo-session lifecycle + write guard (SPEC §3.1 AUTH-02, slice-008 §A / Decision I).
 * Aliased `demo` and prepended right BEFORE Laravel's route-model binding
 * ({@see \Illuminate\Routing\Middleware\SubstituteBindings}) — AFTER {@see EnsureRole}
 * and {@see EnsureOrganizationScope} — so a wrong-ROLE demo user is a 403 before the
 * demo block runs and the per-request org context is already set. It only ever runs for
 * an authenticated request (the admin groups carry `auth` ahead of it) and reads the
 * demo flags stamped by {@see DemoLoginController}.
 *
 * For a real (non-demo) session this is a strict no-op — real users are never affected.
 * For a demo session it:
 *   1. Expires the sandbox once its 30-minute TTL elapses (logout + invalidate + token
 *      rotation + redirect to the login screen with a `demo.expired` flash).
 *   2. Publishes the per-session isolation tag into {@see DemoContext} (carried for
 *      cleanup-tag symmetry; unlike UNIGES the CMS reuses {@see OrganizationScope}, so
 *      the tag does NOT drive a scope here — Decision B).
 *   3. BLOCKS every destructive mutation route ({@see self::DESTRUCTIVE_ROUTE_NAMES})
 *      with a graceful 302 + `demo.blocked` flash — v1 demo is READ-ONLY, so no demo
 *      write ever mutates shared/real data or escapes the org sandbox.
 *   4. Slides the TTL forward on every active request.
 */
final class DemoSessionMiddleware
{
    /**
     * Every state-mutating admin route name (v1 demo is READ-ONLY — block ALL writes,
     * slice-008 §A). Confirmed one-by-one against routes/web.php. The demo presets are
     * gated below super_admin, so a demo user already 403s on the super_admin-only
     * organizations routes via {@see EnsureRole}; listing them here is defence in depth
     * (the block fires even if a gate ever widened). Index/read GETs are deliberately
     * omitted so a demo user can freely browse the admin shell.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE_ROUTE_NAMES = [
        'admin.articles.store',
        'admin.articles.update',
        'admin.articles.destroy',
        'admin.articles.publish',
        'admin.articles.archive',
        'admin.categories.store',
        'admin.categories.update',
        'admin.categories.destroy',
        'admin.jobs.store',
        'admin.jobs.update',
        'admin.jobs.destroy',
        'admin.jobs.status',
        'admin.branches.store',
        'admin.branches.update',
        'admin.branches.destroy',
        'admin.representatives.store',
        'admin.representatives.update',
        'admin.representatives.destroy',
        'admin.directors.store',
        'admin.directors.update',
        'admin.directors.destroy',
        'admin.municipalities.store',
        'admin.municipalities.update',
        'admin.municipalities.destroy',
        'admin.organizations.store',
        'admin.organizations.update',
        'admin.organizations.destroy',
        'admin.members.approve',
        'admin.members.reject',
        'admin.users.store',
        'admin.users.update',
        'admin.users.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('is_demo') !== true) {
            return $next($request);
        }

        $expiresAtRaw = $request->session()->get('demo_expires_at');
        $expiresAt = is_numeric($expiresAtRaw) ? (int) $expiresAtRaw : 0;

        if (now()->timestamp > $expiresAt) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('demo.expired'));
        }

        $sessionId = $request->session()->get('demo_session_id');
        app(DemoContext::class)->set(is_string($sessionId) ? $sessionId : null);

        if (in_array($request->route()?->getName(), self::DESTRUCTIVE_ROUTE_NAMES, strict: true)) {
            return back()->with('error', __('demo.blocked'));
        }

        $request->session()->put('demo_expires_at', now()->addMinutes(30)->timestamp);

        return $next($request);
    }
}
