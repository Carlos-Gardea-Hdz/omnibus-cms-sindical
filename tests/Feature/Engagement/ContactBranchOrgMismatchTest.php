<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\from;

uses(RefreshDatabase::class);

/*
 * THE BRANCH→ORG CONSISTENCY ASSERTION (CONTRACT §4/§12.4, SPEC §3.5). A contact message
 * carries BOTH an organization_id AND a branch_id from the PUBLIC payload — the submitter
 * chooses WHO they are contacting. A hostile or buggy payload must not be able to file a
 * message under org A's name against org B's branch. SubmitContactAction (via the
 * Engagement-local AssertsBranchBelongsToOrganization trait, copied from slice-004) runs a
 * SCOPE-FREE check that the chosen branch physically belongs to the chosen org; on mismatch
 * it throws Spatie's ValidationException → 302 + a branch_id session error
 * (contact.error.branch_org_mismatch), NEVER 422, and NO row lands. The matching pair
 * succeeds. This test goes RED against a naive impl that skips the assertion and persists
 * the cross-org pair. Runs on PostgreSQL 18 (RefreshDatabase). All fixtures are FICTIONAL.
 */

it('refuses a branch_id belonging to a DIFFERENT org: 302 + branch_id error, no row', function (): void {
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);
    $branchB = Branch::factory()->for($orgB)->create();

    from(route('home'))
        ->post(route('contact.store'), [
            'first_name' => 'Cruzado',
            'last_name' => 'Mismatch',
            'email' => 'cruzado.fictional@example.com',
            'phone' => '5512345678',
            'message' => 'Org A pero sucursal de org B.',
            'organization_id' => $orgA->getKey(), // claims A
            'branch_id' => $branchB->getKey(),     // but a branch of B
        ])
        ->assertRedirect(route('home'))
        ->assertStatus(302) // never 422
        ->assertSessionHasErrors(['branch_id' => __('contact.error.branch_org_mismatch')]);

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

it('accepts a branch_id that DOES belong to the chosen org (the matching pair succeeds)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    from(route('home'))
        ->post(route('contact.store'), [
            'first_name' => 'Consistente',
            'last_name' => 'Pareja',
            'email' => 'consistente.fictional@example.com',
            'phone' => '5512345678',
            'message' => 'Org y sucursal coherentes.',
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->where('first_name', 'Consistente')
        ->where('organization_id', $organization->getKey())
        ->where('branch_id', $branch->getKey())
        ->exists())->toBeTrue();
});
