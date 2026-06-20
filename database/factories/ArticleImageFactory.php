<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\ArticleImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ArticleImage>
 */
final class ArticleImageFactory extends Factory
{
    protected $model = ArticleImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'path' => 'articles/'.now()->format('Y/m').'/gallery/'.Str::random(20).'.webp',
            'sort_order' => 0,
        ];
    }
}
