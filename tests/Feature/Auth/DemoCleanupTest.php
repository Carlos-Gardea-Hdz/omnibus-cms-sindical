<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\DemoCleanupAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

/*
 * Scheduled demo pruning (CONTRACT §A: DemoCleanupAction + demo:cleanup command). A
 * demo user older than 30 minutes is force-deleted (users soft-delete, so cleanup must
 * forceDelete past the SoftDeletes trait). The load-bearing guard is
 * `whereNotNull('demo_session_id')`: a REAL user (demo_session_id IS NULL) is NEVER
 * touched, no matter how old. The run is idempotent. Boots PostgreSQL 18.
 */

/** Create a demo user with an explicit creation timestamp + a demo session tag. */
function makeDemoUser(Carbon $createdAt): User
{
    $tag = (string) Str::uuid7();

    return User::factory()
        ->editor()
        ->state([
            'is_demo' => true,
            'demo_session_id' => $tag,
            'email' => "demo+{$tag}@cms.demo",
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])
        ->create();
}

it('force-deletes an expired demo user (older than 30 min) and leaves fresh demo + real users intact', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    $expiredDemo = makeDemoUser(Carbon::parse('2026-06-20 11:20:00')); // 40 min old → expired
    $freshDemo = makeDemoUser(Carbon::parse('2026-06-20 11:55:00'));   // 5 min old → survives
    $realUser = User::factory()->superAdmin()->create();               // demo_session_id NULL → survives

    $deleted = app(DemoCleanupAction::class)->handle();

    expect($deleted)->toBe(1);

    // The expired demo user is GONE — even from the trashed set (forceDelete, not soft-delete).
    expect(User::withTrashed()->whereKey($expiredDemo->getKey())->exists())->toBeFalse();

    // The fresh demo user and the real user both survive untouched.
    expect(User::whereKey($freshDemo->getKey())->exists())->toBeTrue()
        ->and(User::whereKey($realUser->getKey())->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('NEVER touches a real user, however old, because the cleanup is guarded by demo_session_id', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    // An ancient real user — well past the 30-min cutoff, but it has no demo_session_id.
    $ancientReal = User::factory()->administrator()->create([
        'created_at' => Carbon::parse('2020-01-01 00:00:00'),
    ]);

    $deleted = app(DemoCleanupAction::class)->handle();

    expect($deleted)->toBe(0)
        ->and(User::whereKey($ancientReal->getKey())->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('is idempotent: a second run after pruning deletes nothing', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    makeDemoUser(Carbon::parse('2026-06-20 11:00:00')); // expired

    expect(app(DemoCleanupAction::class)->handle())->toBe(1);
    expect(app(DemoCleanupAction::class)->handle())->toBe(0);

    Carbon::setTestNow();
});

it('prunes expired demo users via the demo:cleanup artisan command', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

    $expired = makeDemoUser(Carbon::parse('2026-06-20 11:00:00'));

    artisan('demo:cleanup')->assertExitCode(0);

    expect(User::withTrashed()->whereKey($expired->getKey())->exists())->toBeFalse();

    Carbon::setTestNow();
});
