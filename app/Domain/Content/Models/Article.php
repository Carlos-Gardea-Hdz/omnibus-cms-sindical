<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use App\Support\OrganizationScope;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article (SPEC §6.3.8). `content` is a TipTap/ProseMirror JSONB tree, sanitized
 * against the §3.3 whitelist on store. SoftDeletes; image rows cascade only at a
 * physical (hard) delete. `author` and `category` are non-nullable belongsTo
 * (RESTRICT FK) — never |null in PHPDoc, so PHPStan L9 stays green.
 *
 * Slice-003 retrofit: org-scoped via the global OrganizationScope (a manager/editor
 * sees only their own org's articles; super_admin/administrator/CLI are unconfined).
 * `organization_id`/`branch_id` were added NULLABLE with a restrict FK (Deviation C);
 * the Create/Update Actions stamp organization_id from the author so every NEW
 * article is non-null. Both belongsTo are NULLABLE (existing rows may be null) — so
 * `?Organization`/`?Branch` in PHPDoc, |null on the columns.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $branch_id
 * @property int $category_id
 * @property int $author_id
 * @property string $title
 * @property string $slug
 * @property string|null $subtitle
 * @property array<string, mixed> $content
 * @property string|null $signature
 * @property string|null $featured_image_path
 * @property ArticleStatus $status
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int $views_count
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read User $author
 * @property-read Category $category
 * @property-read Organization|null $organization
 * @property-read Branch|null $branch
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ArticleImage> $images
 */
final class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'branch_id',
        'category_id',
        'author_id',
        'title',
        'slug',
        'subtitle',
        'content',
        'signature',
        'featured_image_path',
        'status',
        'meta_title',
        'meta_description',
        'published_at',
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
            'content' => 'array',
            'status' => ArticleStatus::class,
            'published_at' => 'datetime',
            'views_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ArticleImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class);
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return ArticleFactory::new();
    }
}
