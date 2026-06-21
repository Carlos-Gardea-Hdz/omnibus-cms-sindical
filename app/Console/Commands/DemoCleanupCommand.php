<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Actions\DemoCleanupAction;
use Illuminate\Console\Command;

/**
 * Sweep expired demo-session rows (SPEC §13 / AUTH-02, slice 008). Thin wrapper over
 * {@see DemoCleanupAction}, which force-deletes ONLY demo users carrying a non-null
 * `demo_session_id` older than the 30-minute TTL — real users are never touched.
 * Idempotent. Scheduled every 15 minutes in routes/console.php.
 *
 * Output is plain operator-facing English (no i18n key): this is CLI/cron noise, not
 * user copy.
 */
final class DemoCleanupCommand extends Command
{
    protected $signature = 'demo:cleanup';

    protected $description = 'Force-delete expired demo-session users (SPEC §13 / AUTH-02).';

    public function handle(DemoCleanupAction $action): int
    {
        $count = $action->handle();

        $this->info("Cleaned {$count} demo session user(s).");

        return self::SUCCESS;
    }
}
