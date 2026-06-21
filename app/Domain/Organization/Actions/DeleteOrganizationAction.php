<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Analytics\Models\DailySnapshot;
use App\Domain\Analytics\Models\PageView;
use App\Domain\Content\Models\Article;
use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Director;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Delete an Organization (soft delete). An organization that is still REFERENCED by any
 * restrictOnDelete() child is refused gracefully: the pre-check throws
 * OrganizationInUseException (rendered as a 302 + `organization` field error in
 * bootstrap/app.php) BEFORE any mutation, so no restrict FK is ever tripped and no 500
 * reaches the user (SPEC §3.2 ORG-01).
 *
 * Every model holding an `organization_id ... restrictOnDelete()` FK is checked: Branch,
 * Director, User, Article, JobPosting, ContactMessage, PageView, DailySnapshot. (Member is
 * SET NULL on delete, so it is deliberately EXCLUDED — a member row never blocks the
 * delete.) The check is scope-free (withoutGlobalScope) so it sees EVERY org's children
 * regardless of the acting context, and — for the SOFT-DELETABLE referrers (Branch, Director,
 * User, Article, JobPosting) — withTrashed, because a trashed row STILL physically holds the
 * FK and would still trip the restrict. ContactMessage, PageView and DailySnapshot are
 * hard-delete-only (no `deleted_at`), so they are checked without withTrashed.
 */
final class DeleteOrganizationAction
{
    /**
     * The soft-deletable restrict referrers — checked withTrashed (a trashed row still holds
     * the FK).
     *
     * @var list<class-string<Model>>
     */
    private const SOFT_DELETABLE_REFERRERS = [Branch::class, Director::class, User::class, Article::class, JobPosting::class];

    /**
     * The hard-delete-only restrict referrers (no `deleted_at`) — checked as-is.
     *
     * @var list<class-string<Model>>
     */
    private const HARD_REFERRERS = [ContactMessage::class, PageView::class, DailySnapshot::class];

    public function handle(Organization $organization): void
    {
        if ($this->isReferenced($organization)) {
            throw new OrganizationInUseException(__('organizations.error.has_branches'));
        }

        DB::transaction(static fn (): ?bool => $organization->delete());
    }

    /**
     * True when ANY restrictOnDelete() child still references this organization. Soft-
     * deletable referrers are checked withTrashed (a trashed row still holds the FK); the
     * hard-delete-only referrers are checked as-is.
     */
    private function isReferenced(Organization $organization): bool
    {
        $organizationId = $organization->getKey();

        foreach (self::SOFT_DELETABLE_REFERRERS as $model) {
            if ($this->referrerExists($model, $organizationId, withTrashed: true)) {
                return true;
            }
        }

        foreach (self::HARD_REFERRERS as $model) {
            if ($this->referrerExists($model, $organizationId, withTrashed: false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a scope-free (and optionally withTrashed) query on the given org-owned
     * model finds at least one row for this organization.
     *
     * @param  class-string<Model>  $model
     */
    private function referrerExists(string $model, mixed $organizationId, bool $withTrashed): bool
    {
        /** @var Builder<Model> $query */
        $query = $model::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId);

        if ($withTrashed) {
            /** @phpstan-ignore-next-line method.notFound (SoftDeletes macro on the soft-deletable referrers) */
            $query->withTrashed();
        }

        return $query->exists();
    }
}
