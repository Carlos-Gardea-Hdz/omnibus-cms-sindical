<?php

declare(strict_types=1);

use App\Domain\Organization\Actions\CreateDirectorAction;
use App\Domain\Organization\Data\DirectorData;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationContext;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * WARN 3 — CreateDirectorAction stamps the org from CONTEXT, never from a confined
 * actor's payload. Unlike CreateBranch/Representative/JobPosting, it previously copied
 * organization_id straight from the DTO. Not exploitable today (Director routes are
 * role:administrator → unconfined), but the guard keeps the org-stamp invariant uniform:
 * a CONFINED actor's forged payload organization_id must be IGNORED in favour of the
 * context org, while an UNCONFINED actor's payload is honoured.
 */

it('IGNORES a confined actor payload organization_id and stamps the director from the context org', function (): void {
    $contextOrg = Organization::factory()->create(['name' => 'Context Org']);
    $forgedOrg = Organization::factory()->create(['name' => 'Forged Org']);

    // Simulate a confined actor (manager/editor) pinned to the context org.
    app(OrganizationContext::class)->confineTo($contextOrg->getKey());

    $director = app(CreateDirectorAction::class)->handle(new DirectorData(
        organization_id: $forgedOrg->getKey(), // a FORGED foreign org
        first_name: 'Stamped',
        last_name: 'FromContext',
    ));

    // The director landed in the CONTEXT org, not the forged payload org.
    expect($director->organization_id)->toBe($contextOrg->getKey())
        ->and($director->organization_id)->not->toBe($forgedOrg->getKey());

    // The org's 1:1 director pointer was wired to the CONTEXT org, not the forged one.
    expect(Organization::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($contextOrg->getKey())->value('director_id'))->toBe($director->getKey());
    expect(Organization::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($forgedOrg->getKey())->value('director_id'))->toBeNull();
});

it('HONOURS an unconfined actor payload organization_id (super_admin / administrator)', function (): void {
    $targetOrg = Organization::factory()->create(['name' => 'Target Org']);

    // Unconfined context (administrator/super_admin) — the default.
    app(OrganizationContext::class)->unconfine();

    $director = app(CreateDirectorAction::class)->handle(new DirectorData(
        organization_id: $targetOrg->getKey(),
        first_name: 'Honoured',
        last_name: 'Payload',
    ));

    expect($director->organization_id)->toBe($targetOrg->getKey());
    expect(Director::withoutGlobalScope(OrganizationScope::class)
        ->where('organization_id', $targetOrg->getKey())->exists())->toBeTrue();
});
