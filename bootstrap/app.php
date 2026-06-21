<?php

declare(strict_types=1);

use App\Domain\Content\Exceptions\CategoryInUseException;
use App\Domain\Content\Exceptions\InvalidArticleTransitionException;
use App\Domain\Jobs\Exceptions\InvalidJobTransitionException;
use App\Domain\Membership\Exceptions\InvalidMemberTransitionException;
use App\Domain\Organization\Exceptions\BranchHasRepresentativesException;
use App\Domain\Organization\Exceptions\DirectorAlreadyAssignedException;
use App\Domain\Organization\Exceptions\MunicipalityInUseException;
use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Http\Middleware\EnsureOrganizationScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Traefik (TLS termination) — trust forwarded proto/host headers.
        $middleware->trustProxies(at: '*');

        // EnsureOrganizationScope runs on EVERY web request (not just the admin shell): it
        // RESETS the per-request OrganizationContext from the acting user, so a request that
        // carries no confinement (the public article view, the landing page, login) is always
        // freshly UNCONFINED — never inheriting a prior request's manager/editor confinement
        // from the shared per-request singleton. It must run BEFORE SubstituteBindings (see the
        // priority insert below) so route-model binding resolves a cross-org {article}/{branch}
        // under the active scope (→ 404), and AFTER StartSession so $request->user() is resolved.
        $middleware->web(append: [
            EnsureOrganizationScope::class,
            App\Http\Middleware\HandleInertiaRequests::class,
            Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Level-based RBAC gate (SPEC §3.1 AUTH-03, §10.2): `->middleware('role:editor')`.
        // The 'auth' and 'guest' aliases ship with Laravel — no registration needed.
        // The 'org.scope' alias (SPEC §10.3, slice-003 §3) is kept for the admin route groups in
        // routes/web.php as an explicit, documented marker of the org-scoping contract; it points
        // at the same EnsureOrganizationScope, which is idempotent (it just (re)sets the context),
        // so running it again on a route group is harmless. It NEVER aborts — it only filters data.
        $middleware->alias([
            'role' => App\Http\Middleware\EnsureRole::class,
            'org.scope' => EnsureOrganizationScope::class,
        ]);

        // CRITICAL ordering (the security spine, mirroring UNIGES DemoSessionMiddleware): both
        // the RBAC gate and the org context must run BEFORE Laravel's route-model binding, in
        // this exact order — role first, org.scope second, then SubstituteBindings:
        //   1. EnsureRole (role:*) decides ACCESS — a wrong-LEVEL user is a 403 BEFORE any row is
        //      bound, so an under-privileged user never leaks a 404/200 about a row's existence.
        //   2. EnsureOrganizationScope sets the per-request confinement, so when binding runs a
        //      manager hitting a CROSS-ORG /admin/articles/{article}/edit resolves the row under
        //      the OrganizationScope and gets a 404 (unresolvable), never a 200 with another org's
        //      data — while a wrong-ROLE user already got a 403 above.
        //   3. SubstituteBindings then resolves {article}/{branch}/… under the active scope.
        // This makes the precedence precise: wrong role → 403; right role, wrong org → 404.
        // Prepending (not replacing) keeps the framework's default priority order intact; both
        // land right before SubstituteBindings and after StartSession/auth (so $request->user()
        // is resolved). EnsureRole is registered first so it sits ahead of EnsureOrganizationScope.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: App\Http\Middleware\EnsureRole::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureOrganizationScope::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Deleting a category another article still references is a referential
        // guard, not a server fault (SPEC §3.3 CAT-02): surface it as a graceful
        // 302 + a `category` field error on web (422 for JSON), never an unhandled
        // 500 from the restrict FK. Mirrors UNIGES CatalogInUseException.
        $exceptions->render(function (CategoryInUseException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['category' => $e->getMessage()]);
        });

        // An illegal ArticleStatus transition (e.g. archived→draft) is a domain
        // guard, not a server fault (SPEC §3.3 NEWS-07): surface it as the same
        // graceful 302 + a `status` field error on web (422 for JSON), never a 500.
        // Mirrors UNIGES InvalidStatusTransitionException.
        $exceptions->render(function (InvalidArticleTransitionException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['status' => $e->getMessage()]);
        });

        // An illegal JobStatus transition (e.g. closed→active) is a domain guard, not a
        // server fault (SPEC §3.4 JOB-02 / slice-004 §6): surface it as the same graceful
        // 302 + a `status` field error on web (422 for JSON), never a 500. Mirrors the
        // Article transition render above.
        $exceptions->render(function (InvalidJobTransitionException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['status' => $e->getMessage()]);
        });

        // An illegal MemberStatus transition (e.g. approve/reject an already-terminal member) is a
        // domain guard, not a server fault (SPEC §3.6 MEMBER-02 / slice-005 §6): surface it as the
        // same graceful 302 + a `status` field error on web (422 for JSON), never a 500. Mirrors the
        // Article / Job transition renders above.
        $exceptions->render(function (InvalidMemberTransitionException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['status' => $e->getMessage()]);
        });

        // The four Organization referential/uniqueness guards (SPEC §3.2, §6.1, §11.4 /
        // slice-003 §7) are domain rules, not server faults: each surfaces as the same
        // graceful 302 + a field error on web (422 for JSON), never a restrict-FK or
        // unique-index 500. Field keys: municipality / organization / branch / director.

        // Deleting a municipality still referenced by an organization (restrict FK).
        $exceptions->render(function (MunicipalityInUseException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['municipality' => $e->getMessage()]);
        });

        // Deleting an organization that still owns branches (restrict FK).
        $exceptions->render(function (OrganizationInUseException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['organization' => $e->getMessage()]);
        });

        // Deleting a branch that still holds representatives (§11.4 #3).
        $exceptions->render(function (BranchHasRepresentativesException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['branch' => $e->getMessage()]);
        });

        // Assigning a second director to an organization (one-per-org unique guard).
        $exceptions->render(function (DirectorAlreadyAssignedException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['director' => $e->getMessage()]);
        });
    })->create();
