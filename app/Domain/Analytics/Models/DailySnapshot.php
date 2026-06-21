<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Database\Factories\DailySnapshotFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DailySnapshot — a per-org daily pre-aggregation row (SPEC §6.3.13). SHIPPED for schema
 * completeness (Decision F option (i)) but NOT read by the MVP dashboard: the dashboard
 * reads LIVE aggregates off the source tables. The nightly RollDailySnapshotsJob that
 * upserts these counts is DEFERRED to a scaling phase (spec §"Out of scope").
 *
 * Born org-scoped via the global {@see OrganizationScope} (symmetric with every other
 * org-scoped model). organization belongsTo {@see Organization} (FK RESTRICT, NOT NULL —
 * never |null). The unique [organization_id, date] guarantees one row per org per day.
 *
 * @property int $id
 * @property int $organization_id
 * @property \Illuminate\Support\Carbon $date
 * @property int $articles_count
 * @property int $jobs_count
 * @property int $members_count
 * @property int $contact_messages_count
 * @property int $page_views_count
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Organization $organization
 */
final class DailySnapshot extends Model
{
    /** @use HasFactory<DailySnapshotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'date',
        'articles_count',
        'jobs_count',
        'members_count',
        'contact_messages_count',
        'page_views_count',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(new OrganizationScope);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'articles_count' => 'integer',
            'jobs_count' => 'integer',
            'members_count' => 'integer',
            'contact_messages_count' => 'integer',
            'page_views_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return DailySnapshotFactory::new();
    }
}
