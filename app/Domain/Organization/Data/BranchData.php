<?php

declare(strict_types=1);

namespace App\Domain\Organization\Data;

use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Branch create/update payload (manager-facing).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type.
 * organization_id is validated to an existing organization; the TENANT INVARIANT
 * (a confined manager may only target their OWN org) is NOT this DTO's job — the DTO is
 * route-agnostic. The Create/Update Branch Actions resolve the effective organization_id
 * server-side from the OrganizationContext (forcing the confined org, ignoring any
 * payload-supplied org), mirroring the Article author-stamp pattern.
 */
#[TypeScript]
final class BranchData extends Data
{
    public function __construct(
        #[Required, Exists('organizations', 'id')]
        public int $organization_id,
        #[Required, Max(100)]
        public string $name,
        #[Required, Max(100)]
        public string $location,
    ) {}
}
