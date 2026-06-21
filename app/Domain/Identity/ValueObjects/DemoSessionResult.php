<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObjects;

use App\Domain\Identity\Enums\DemoPreset;
use App\Models\User;

/**
 * Transport object returned by ProvisionDemoSessionAction (slice 008).
 *
 * It carries the freshly minted, demo-tagged User together with the session tag the
 * Action assigned and the preset it provisioned. The HTTP layer consumes it to log
 * the user in and seed the session flags — keeping the Action free of Illuminate\Http
 * (it returns this VO instead of touching Auth/session), exactly as
 * AuthenticateUserAction returns a User.
 *
 * Deliberately NOT marked #[TypeScript]: it is server-only and the demoSessionId it
 * holds is the isolation token, which must never reach the client. Carrying an
 * Eloquent model as a transport between Action and controller is acceptable here —
 * the VO is never persisted.
 */
final readonly class DemoSessionResult
{
    public function __construct(
        public User $user,
        public string $demoSessionId,
        public DemoPreset $preset,
    ) {}
}
