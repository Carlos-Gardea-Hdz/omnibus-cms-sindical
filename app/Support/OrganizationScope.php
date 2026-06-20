<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The symmetric tenant-isolation scope (slice 003).
 *
 * Applied via booted() to every org-scoped model (Article, Branch, Director,
 * Representative). It reads the active confinement from the per-request
 * OrganizationContext singleton (set by EnsureOrganizationScope) and constrains
 * EVERY query symmetrically:
 *
 *   - Unconfined (super_admin / administrator / CLI / queue / seeders / no user)
 *       → no-op (sees all rows). For super_admin this is genuine cross-org; for
 *         non-HTTP paths it preserves the global access the 248 prior tests rely on.
 *   - Confined to org X (manager / editor)
 *       → WHERE <table>.organization_id = X.
 *   - Confined but org-less (a manager misconfigured with a null organization_id)
 *       → WHERE 1 = 0 — fail CLOSED (sees NOTHING), never fail-open to every org.
 *
 * The qualified column (`<table>.organization_id`) keeps the predicate unambiguous
 * under joins (the UNIGES DemoScope lesson). Cross-org administrative operations
 * (super_admin Delete Actions) bypass this scope explicitly with
 * Model::withoutGlobalScope(OrganizationScope::class).
 *
 * Lives under App\Support (domain-neutral) so the Content/Organization models can
 * add the global scope WITHOUT importing App\Domain\Identity (which the arch tests
 * forbid). Direct mirror of UNIGES DemoScope.
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganizationContext::class);

        if ($context->isUnconfined()) {
            return;
        }

        $organizationId = $context->organizationId();

        if ($organizationId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
