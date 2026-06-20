<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\ArticleStatus;
use App\Models\User;
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
 * @property int $id
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ArticleImage> $images
 */
final class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
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
