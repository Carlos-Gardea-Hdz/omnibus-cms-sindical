<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\OrganizationContext;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
