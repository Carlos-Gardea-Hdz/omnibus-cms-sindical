<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Content\Models\Article;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationScope;
use Database\Factories\PageViewFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PageView — an APPEND-ONLY public article-view event (SPEC §6.3.14, §3.7 ANALYTICS-01).
 * The SOLE write is RecordPageViewAction (an atomic insert behind a 24h dedup gate); the
 * row is never updated or deleted. Deliberate model facts:
 *   - `$timestamps = false` — the table has only `viewed_at` (the event clock), no
 *     created_at/updated_at. NO SoftDeletes (an append-only event is never soft-deleted).
 *   - Born org-scoped: the global {@see OrganizationScope} confines the dashboard's
 *     aggregate reads (a manager/administrator sees only their org's page-views; the WRITE
 *     path is unconfined — the scope only filters SELECT, so the public INSERT is a no-op
 *     pass-through and the org is derived from the article, never from a payload).
 *   - `ip_hash` is a SHA-256 hex digest (the raw IP is hashed at the edge and never stored,
 *     §10.5). The dashboard surfaces ONLY aggregates — never an individual ip_hash/UA prop.
 *
 * Cross-domain MODEL references (arch-allowed): article belongsTo {@see Article} and
 * organization belongsTo {@see Organization} — FK relation targets, NOT calls into another
 * domain's Actions. article is NULLABLE (FK SET NULL — a view survives its article's
 * hard-delete); organization is NON-nullable (FK RESTRICT — never |null in PHPDoc).
 *
 * @property int $id
 * @property int|null $article_id
 * @property int $organization_id
 * @property string $ip_hash
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $viewed_at
 * @property-read Article|null $article
 * @property-read Organization $organization
 */
final class PageView extends Model
{
    /** @use HasFactory<PageViewFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'article_id',
        'organization_id',
        'ip_hash',
        'user_agent',
        'viewed_at',
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
            'viewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
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
        return PageViewFactory::new();
    }
}
