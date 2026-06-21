<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request holder for the active demo-session tag (slice 008).
 *
 * Bound as a singleton in AppServiceProvider. Unlike UNIGES — where the equivalent
 * holder drives a dedicated DemoScope — the CMS reuses the existing
 * {@see OrganizationScope} for tenant isolation (Decision B): a demo user is a real,
 * org-confined member of the seeded showcase organization, so the OrganizationContext
 * already confines its reads/writes to that org. This holder therefore does NOT drive
 * any global scope; it merely carries the session tag for cleanup-tag symmetry — the
 * DemoSessionMiddleware publishes the tag here so request-scoped code (and any future
 * tag-aware logic) can read it without re-touching the session.
 *
 * It starts null and STAYS null on real (non-demo) HTTP requests, on CLI requests, and
 * on the scheduled cleanup. It lives under App\Support (a domain-neutral location),
 * mirroring OrganizationContext, so it never entangles a consuming model with
 * App\Domain\Identity (which the architecture tests forbid).
 */
final class DemoContext
{
    private ?string $sessionId = null;

    /** Set (or clear) the active demo session tag for this request. */
    public function set(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /** The active demo session tag, or null on real/CLI requests. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }
}
