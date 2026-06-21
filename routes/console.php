<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep expired demo-session users every 15 minutes (SPEC §13 / AUTH-02, slice 008).
// withoutOverlapping() guards against a long sweep colliding with the next tick.
Schedule::command('demo:cleanup')->everyFifteenMinutes()->withoutOverlapping();
