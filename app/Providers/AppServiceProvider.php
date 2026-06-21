<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Analytics\Actions\RecordPageViewAction;
use App\Domain\Analytics\Contracts\RecordsPageViews;
use App\Support\DemoContext;
use App\Support\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request org confinement (slice 003). One instance shared by the
        // EnsureOrganizationScope middleware (writer) and the OrganizationScope
        // global scope (reader). Defaults UNCONFINED — the regression firewall:
        // CLI / queue / seeders / tests-without-org.scope see all rows.
        $this->app->singleton(OrganizationContext::class);

        // Per-request demo-session tag holder (slice 008). One instance shared by the
        // DemoSessionMiddleware (writer) and ProvisionDemoSessionAction (writer); it
        // carries the session tag for cleanup-tag symmetry. Starts null on every real /
        // CLI request (the CMS reuses OrganizationScope for isolation — Decision B — so
        // this drives no global scope).
        $this->app->singleton(DemoContext::class);

        // The page-view recording contract (slice 007) → the final Action. Fronting the
        // SOLE Analytics write path behind an interface keeps the Action `final` (the arch
        // rule) while letting the public article controller depend on the abstraction and
        // the fail-soft tracking be swapped/mocked in tests.
        $this->app->bind(RecordsPageViews::class, RecordPageViewAction::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The demo-login IP rate limiter (AUTH-02, §10.4): 10 demo logins per hour
        // per IP, a SEPARATE limiter from `POST /login`. Exceeding it follows the web
        // convention — a graceful 302 back with a `preset` session error (never a 429
        // JSON body), so no demo user can be minted by spamming the endpoint.
        RateLimiter::for('demo-login', static fn (Request $request): Limit => Limit::perHour(10)
            ->by($request->ip() ?? 'unknown')
            ->response(static fn () => back()->withErrors(['preset' => __('demo.throttled')])));
    }
}
