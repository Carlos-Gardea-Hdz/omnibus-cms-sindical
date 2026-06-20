<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request holder for the active organization confinement (slice 003).
 *
 * Bound as a singleton in AppServiceProvider so the OrganizationScope global scope
 * and the EnsureOrganizationScope middleware share one instance per request. It is
 * the direct mirror of UNIGES DemoContext.
 *
 * It DEFAULTS to UNCONFINED and STAYS unconfined on CLI requests, on the scheduler /
 * queue worker, and in seeders / tests that don't run a confined manager through the
 * `org.scope` middleware. That default is the regression firewall for the 248 prior
 * tests: when nothing confines the context, the OrganizationScope is a no-op and the
 * slice-002 Content queries behave exactly as before.
 *
 * Confinement is opt-in: the EnsureOrganizationScope middleware calls confineTo() for
 * a manager/editor (level <= Manager) and unconfine() for administrator/super_admin.
 *
 * It is placed under App\Support (a domain-neutral location) rather than under
 * App\Domain\Identity on purpose: the Content/Organization models consume the
 * OrganizationScope, and the architecture tests forbid those domains from importing
 * App\Domain\Identity. A neutral home keeps cross-domain isolation intact.
 */
final class OrganizationContext
{
    private ?int $organizationId = null;

    private bool $unconfined = true;

    /**
     * Confine every org-scoped query to this organization id for the rest of the
     * request. A null id means "confined but org-less" — the scope fails CLOSED
     * (sees nothing) rather than fail-open to every organization.
     */
    public function confineTo(?int $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->unconfined = false;
    }

    /** Lift confinement — org-scoped queries see all rows (super_admin / CLI). */
    public function unconfine(): void
    {
        $this->organizationId = null;
        $this->unconfined = true;
    }

    /** True when the context is unconfined (the default; super_admin/administrator/CLI). */
    public function isUnconfined(): bool
    {
        return $this->unconfined;
    }

    /** The confined organization id, or null (unconfined, or confined-but-org-less). */
    public function organizationId(): ?int
    {
        return $this->organizationId;
    }
}
