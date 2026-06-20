<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Content\Models\Article;
use App\Support\OrganizationScope;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Branch — a sucursal of an organization (SPEC §6.3.3). Org-scoped: the global
 * OrganizationScope confines a manager/editor to their own org's branches (a
 * cross-org route-model-bound branch becomes unresolvable → 404); super_admin /
 * administrator / CLI are unconfined. SoftDeletes. Deletion is the load-bearing
 * §3.2 ORG-02 case: blocked if representatives exist (BranchHasRepresentativesException),
 * else an application-level soft-delete cascade to its articles (DeleteBranchAction).
 *
 * `organization` is a non-nullable belongsTo (RESTRICT FK) — never |null.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $location
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Organization $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Representative> $representatives
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Article> $articles
 */
final class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'location',
    ];

    protected static function booted(): void
    {
        self::addGlobalScope(new OrganizationScope);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Representative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(Representative::class);
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return BranchFactory::new();
    }
}
