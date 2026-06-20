<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use Database\Factories\ArticleImageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gallery image belonging to an article (SPEC §6.3.9). A true child — its
 * parent FK cascades at a physical delete. `article` is non-nullable belongsTo.
 *
 * @property int $id
 * @property int $article_id
 * @property string $path
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Article $article
 */
final class ArticleImage extends Model
{
    /** @use HasFactory<ArticleImageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'article_id',
        'path',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return ArticleImageFactory::new();
    }
}
