<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Purge expired demo-session data (SPEC §13 / AUTH-02, slice 008).
 *
 * The cutoff is 30 minutes ago: any demo user older than that belongs to a session
 * that has timed out. v1 demo is READ-ONLY, so a demo session owns no scoped child
 * rows — the only ephemeral artifact is the demo User itself, so this is a single,
 * FK-safe delete. Every delete carries the falsifiable guard
 * `whereNotNull('demo_session_id')` — the load-bearing invariant: this command can
 * never touch a real user row (a real user's demo_session_id IS NULL).
 *
 * Users now SoftDelete (slice 008 migration), so the rows are FORCE-deleted —
 * physically gone, no soft-deleted growth and no orphaned FK. The authored content's
 * author_id is unaffected: a demo user authors nothing in a read-only demo. Runs from
 * CLI with no org context (UNCONFINED), so the OrganizationScope is a no-op and a
 * timed-out demo user in ANY org is reachable. Idempotent: a second run with nothing
 * expired deletes zero.
 */
final class DemoCleanupAction
{
    /**
     * @return int the number of demo users physically deleted
     */
    public function handle(?CarbonInterface $now = null): int
    {
        $cutoff = ($now ?? now())->copy()->subMinutes(30);

        return DB::transaction(function () use ($cutoff): int {
            $deleted = User::query()
                ->withTrashed()
                ->whereNotNull('demo_session_id')
                ->where('created_at', '<', $cutoff)
                ->forceDelete();

            return is_numeric($deleted) ? (int) $deleted : 0;
        });
    }
}
