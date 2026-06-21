<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Analytics\Actions\RecordPageViewAction;
use App\Domain\Analytics\Contracts\RecordsPageViews;
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
        //
    }
}
