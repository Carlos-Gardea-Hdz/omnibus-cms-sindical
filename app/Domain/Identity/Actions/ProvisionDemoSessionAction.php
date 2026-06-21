<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\DemoLoginData;
use App\Domain\Identity\ValueObjects\DemoSessionResult;
use App\Domain\Organization\Models\Municipality;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\DemoContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provision one ephemeral demo session (SPEC §13 / AUTH-02, slice 008).
 *
 * A single business operation, wholly inside one DB::transaction:
 *
 *   1. Resolve (creating once, idempotently) the shared, fictional "Demo
 *      Organization" the showcase lives in — every demo user is a real, org-confined
 *      member of it, so the existing OrganizationScope already isolates a demo
 *      visitor's reads/writes to that org (Decision B: no dedicated DemoScope).
 *   2. Mint a fresh UUIDv7 session tag (chronologically sortable, Law §VO).
 *   3. Create a demo User carrying the preset's role, the showcase org, and the tag,
 *      with a deterministic, obviously-fake email and a fixed fictional display name
 *      (NO faker PII — the showcase stays deterministic and privacy-safe). The role
 *      can never be super_admin (DemoPreset has no such case — the slice invariant).
 *   4. Return a DemoSessionResult VO. This Action never logs the user in or writes
 *      the session — it stays free of Illuminate\Http, exactly as
 *      AuthenticateUserAction returns a User and lets the HTTP layer do the rest.
 *
 * Each call mints a distinct UUIDv7, so concurrent demo visitors get disjoint
 * sandboxes; v1 demo is READ-ONLY (every destructive route is blocked by the
 * DemoSessionMiddleware), so the per-session tag is primarily an isolation +
 * cleanup handle rather than a write partition.
 */
final class ProvisionDemoSessionAction
{
    /** The fixed name of the shared, fictional showcase organization. */
    private const SHOWCASE_ORGANIZATION_NAME = 'Demo Organization';

    public function __construct(
        private readonly DemoContext $demoContext,
    ) {}

    public function handle(DemoLoginData $data): DemoSessionResult
    {
        $preset = $data->preset;

        $result = DB::transaction(function () use ($preset): DemoSessionResult {
            $showcaseOrganizationId = $this->resolveShowcaseOrganizationId();
            $demoSessionId = (string) Str::uuid7();

            /** @var User $user */
            $user = User::factory()
                ->demo($demoSessionId)
                ->state([
                    'role' => $preset->role(),
                    'name' => $preset->displayName(),
                    'organization_id' => $showcaseOrganizationId,
                ])
                ->create();

            return new DemoSessionResult($user, $demoSessionId, $preset);
        });

        // Activate the freshly minted sandbox's tag for the remainder of this request.
        // The DemoSessionMiddleware re-establishes the same tag on later requests.
        $this->demoContext->set($result->demoSessionId);

        return $result;
    }

    /**
     * The id of the shared fictional showcase organization, created once and reused
     * thereafter (firstOrCreate on the fixed name). If no municipality exists yet, the
     * factory mints one — so a bare environment can still provision a demo. Idempotent:
     * concurrent demo logins all converge on the same single org row.
     */
    private function resolveShowcaseOrganizationId(): int
    {
        $existing = Organization::query()
            ->where('name', self::SHOWCASE_ORGANIZATION_NAME)
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $municipality = Municipality::query()->first() ?? Municipality::factory()->create();

        $organization = Organization::factory()
            ->for($municipality)
            ->create(['name' => self::SHOWCASE_ORGANIZATION_NAME]);

        return $organization->id;
    }
}
