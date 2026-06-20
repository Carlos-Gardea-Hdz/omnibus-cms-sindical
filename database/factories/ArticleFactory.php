<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
final class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $title */
        $title = fake()->unique()->sentence(6);
        $title = Str::limit($title, 140, '');

        return [
            'category_id' => Category::factory(),
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'subtitle' => fake()->optional()->sentence(8),
            'content' => $this->sampleContent(),
            'signature' => fake()->optional()->name(),
            'featured_image_path' => null,
            'status' => ArticleStatus::Draft,
            'meta_title' => fake()->optional()->sentence(4),
            'meta_description' => fake()->optional()->text(150),
            'views_count' => 0,
            'published_at' => null,
        ];
    }

    /** A draft (default), explicit for readability. */
    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ArticleStatus::Draft,
            'published_at' => null,
        ]);
    }

    /** A published article — featured image present + published_at set. */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ArticleStatus::Published,
            'featured_image_path' => 'articles/'.now()->format('Y/m').'/'.Str::random(20).'.webp',
            'published_at' => now(),
        ]);
    }

    /** An archived article. */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ArticleStatus::Archived,
            'featured_image_path' => 'articles/'.now()->format('Y/m').'/'.Str::random(20).'.webp',
            'published_at' => now()->subDay(),
        ]);
    }

    /** Article that already has a featured image (publish-precondition met). */
    public function withFeaturedImage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'featured_image_path' => 'articles/'.now()->format('Y/m').'/'.Str::random(20).'.webp',
        ]);
    }

    /**
     * A minimal valid TipTap/ProseMirror document.
     *
     * @return array<string, mixed>
     */
    private function sampleContent(): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => fake()->paragraph()],
                    ],
                ],
            ],
        ];
    }
}
