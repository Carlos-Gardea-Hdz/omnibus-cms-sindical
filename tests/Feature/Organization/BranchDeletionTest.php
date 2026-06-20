<?php

declare(strict_types=1);

use App\Domain\Content\Models\Article;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Representative;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * THE load-bearing DeleteBranchAction (CONTRACT §8/§16, SPEC §3.2 ORG-02,
 * §11.4 #2/#3). The two paths are the whole point:
 *
 *   #3 — a branch WITH ≥1 representative is BLOCKED: BranchHasRepresentativesException
 *        → 302 + the 'branch' error; the branch, its representatives AND the restrict
 *        FK on representatives.branch_id are all untouched (NEVER a 500).
 *
 *   #2 — a branch with NO representatives but WITH articles CASCADES at the
 *        application layer (never a DB ON DELETE CASCADE): in one transaction the
 *        branch is soft-deleted, every article with that branch_id is soft-deleted,
 *        and the physical featured-image files of those articles are removed; 302 +
 *        the 'branches.deleted' flash.
 *
 * The acting user is a super_admin (unconfined) so the cascade reaches every one of
 * the branch's articles regardless of org context. Runs on PostgreSQL 18, the
 * featured images on the faked public disk. Article counts that must observe BOTH
 * org A's and the branch's rows use withoutGlobalScope(OrganizationScope) so the
 * assertion is falsifiable, not merely "the data is absent".
 */

/** A super_admin (unconfined — branch management reaches all org rows). */
function branchAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

it('BLOCKS deleting a branch that has representatives: 302 + error, nothing deleted, NEVER 500 (#3)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $representative = Representative::factory()->for($organization)->for($branch)->create();

    actingAs(branchAdmin())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect()
        ->assertSessionHasErrors(['branch' => __('branches.error.has_representatives')]);

    // The pre-check fired before the restrict FK could be tripped — both survive.
    expect(Branch::withoutGlobalScope(OrganizationScope::class)->whereKey($branch->getKey())->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(Representative::withoutGlobalScope(OrganizationScope::class)->whereKey($representative->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
});

it('CASCADES articles when a branch has no representatives: branch + articles soft-deleted, files removed, 302 (#2)', function (): void {
    Storage::fake('public');

    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    // Two articles pinned to this branch, each with a real featured file on disk.
    $imageA = 'articles/2026/06/branch-a.webp';
    $imageB = 'articles/2026/06/branch-b.webp';
    Storage::disk('public')->put($imageA, 'A');
    Storage::disk('public')->put($imageB, 'B');

    $articleA = Article::factory()->forOrganization($organization)->create([
        'branch_id' => $branch->getKey(),
        'featured_image_path' => $imageA,
    ]);
    $articleB = Article::factory()->forOrganization($organization)->create([
        'branch_id' => $branch->getKey(),
        'featured_image_path' => $imageB,
    ]);

    // A sibling article in the SAME org but a DIFFERENT branch must NOT be touched.
    $otherBranch = Branch::factory()->for($organization)->create();
    $survivor = Article::factory()->forOrganization($organization)->create([
        'branch_id' => $otherBranch->getKey(),
        'featured_image_path' => 'articles/2026/06/survivor.webp',
    ]);
    Storage::disk('public')->put($survivor->featured_image_path, 'S');

    actingAs(branchAdmin())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect()
        ->assertSessionHas('success', __('branches.deleted'))
        ->assertSessionHasNoErrors();

    // The branch is soft-deleted (application cascade, not a DB cascade).
    $this->assertSoftDeleted('branches', ['id' => $branch->getKey()]);

    // Both branch articles are soft-deleted; their physical files are gone.
    $this->assertSoftDeleted('articles', ['id' => $articleA->getKey()]);
    $this->assertSoftDeleted('articles', ['id' => $articleB->getKey()]);
    Storage::disk('public')->assertMissing($imageA);
    Storage::disk('public')->assertMissing($imageB);

    // The other branch's article is untouched, file intact.
    expect(Article::withoutGlobalScope(OrganizationScope::class)->whereKey($survivor->getKey())->whereNull('deleted_at')->exists())->toBeTrue();
    Storage::disk('public')->assertExists($survivor->featured_image_path);
});

it('soft-deletes a branch with neither representatives nor articles (302 + deleted flash)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    actingAs(branchAdmin())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect()
        ->assertSessionHas('success', __('branches.deleted'))
        ->assertSessionHasNoErrors();

    $this->assertSoftDeleted('branches', ['id' => $branch->getKey()]);
});

it('CASCADES active job postings on branch delete so the public board never 500s (slice-004 cross-slice guard)', function (): void {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    // An ACTIVE job on the branch, no representatives → the delete proceeds.
    $job = JobPosting::factory()->forBranch($branch)->create(['status' => JobStatus::Active]);

    actingAs(branchAdmin())
        ->delete(route('admin.branches.destroy', $branch))
        ->assertRedirect()
        ->assertSessionHas('success', __('branches.deleted'))
        ->assertSessionHasNoErrors();

    // Branch AND its active job are soft-deleted together — no orphan job pointing at
    // a trashed branch (which would 500 the public board: its eager-load drops the org
    // scope but not SoftDeletes, so $job->branch would be null).
    $this->assertSoftDeleted('branches', ['id' => $branch->getKey()]);
    $this->assertSoftDeleted('job_postings', ['id' => $job->getKey()]);

    // The public board stays reachable and the cascaded job is gone (was a 500 before).
    get(route('jobs.index'))->assertOk();
});
