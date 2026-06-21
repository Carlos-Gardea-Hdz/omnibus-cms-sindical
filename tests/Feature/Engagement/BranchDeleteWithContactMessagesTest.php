<?php

declare(strict_types=1);

use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * THE CROSS-SLICE REGRESSION — Decision E proven (CONTRACT §9/§12.8, SPEC §6.4). The new
 * contact_messages.branch_id is a RESTRICT FK to branches, which SOFT-delete (UPDATE, not
 * SQL DELETE) — so the RESTRICT is never tripped, exactly like articles/job_postings.
 * UNLIKE slice-004's job cascade and slice-005's DeleteMunicipalityAction edit:
 *
 *   - contact_messages is NOT added to DeleteBranchAction's application-level cascade
 *     (the cascade soft-deletes children; a contact message has no deleted_at and is a
 *     PERMANENT audit record — §6.4 — so it SURVIVES the branch's soft delete, physically
 *     pointing at the now-trashed branch).
 *   - DeleteBranchAction stays UNTOUCHED; no Organization arch allow-list edge is added.
 *
 * This is the EXPLICIT CONTRAST with slice-005 (municipalities HARD-delete → that FK had
 * to be pre-checked). The branch here has NO representatives so the delete proceeds (the
 * representative pre-check is the only thing that BLOCKS a branch delete). The contact
 * message must survive physically — a 302 (no 500), no cascade. Runs on PostgreSQL 18
 * (RefreshDatabase). The acting user is a super_admin (unconfined). All fixtures FICTIONAL.
 */

it('survives a contact message when its branch is soft-deleted: 302 (no 500), the message persists physically', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    // A contact message pinned to this branch — and NO representatives, so the branch
    // delete is allowed to proceed (the representative pre-check is the only blocker).
    $message = ContactMessage::factory()->forOrganization($organization)->forBranch($branch)->create();

    actingAs(User::factory()->superAdmin()->create())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect()
        ->assertSessionHas('success', __('branches.deleted'))
        ->assertSessionHasNoErrors(); // NEVER a 500 from the RESTRICT FK

    // The branch is soft-deleted (Decision E: parents soft-delete → RESTRICT never trips).
    $this->assertSoftDeleted('branches', ['id' => $branch->getKey()]);

    // The contact message SURVIVES physically — it is a permanent audit record, NOT part
    // of the branch cascade (it has no deleted_at), still pointing at the trashed branch.
    expect(ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($message->getKey())->exists())->toBeTrue();

    $survivor = ContactMessage::withoutGlobalScope(OrganizationScope::class)
        ->whereKey($message->getKey())->sole();

    expect($survivor->branch_id)->toBe($branch->getKey())
        ->and($survivor->organization_id)->toBe($organization->getKey());
});
