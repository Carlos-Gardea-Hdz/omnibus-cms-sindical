<?php

declare(strict_types=1);

use App\Domain\Content\Exceptions\CategoryInUseException;
use App\Domain\Content\Exceptions\InvalidArticleTransitionException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Traefik (TLS termination) — trust forwarded proto/host headers.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            App\Http\Middleware\HandleInertiaRequests::class,
            Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Level-based RBAC gate (SPEC §3.1 AUTH-03, §10.2): `->middleware('role:editor')`.
        // The 'auth' and 'guest' aliases ship with Laravel — no registration needed.
        $middleware->alias([
            'role' => App\Http\Middleware\EnsureRole::class,
        ]);
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
    })->create();
