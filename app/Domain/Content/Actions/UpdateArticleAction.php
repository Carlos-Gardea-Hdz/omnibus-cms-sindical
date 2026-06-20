<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\ArticleData;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Services\SanitizesContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Update an article (SPEC §3.3 NEWS-02). Content is re-sanitized through the §3.3
 * whitelist before persistence. Slug uniqueness is unique-ignore-self. A newly
 * uploaded featured image replaces the previous one; the old physical file is
 * removed only AFTER the DB write commits, and a freshly written file is cleaned
 * up if the transaction rolls back — no orphaned bytes either way.
 */
final class UpdateArticleAction
{
    public function __construct(
        private readonly SanitizesContent $sanitizer,
    ) {}

    public function handle(Article $article, ArticleData $data): Article
    {
        $slug = Str::slug($data->slug ?: $data->title);

        $this->assertSlugAvailable($slug, $article);

        $content = $this->sanitizer->clean($data->content);

        $oldImagePath = $article->featured_image_path;
        $newImagePath = $data->featured_image?->store('articles/'.now()->format('Y/m'), 'public');

        $attributes = [
            'category_id' => $data->category_id,
            'title' => $data->title,
            'slug' => $slug,
            'subtitle' => $data->subtitle,
            'content' => $content,
            'signature' => $data->signature,
            'meta_title' => $data->meta_title,
            'meta_description' => $data->meta_description,
        ];

        if (is_string($newImagePath)) {
            $attributes['featured_image_path'] = $newImagePath;
        }

        try {
            DB::transaction(function () use ($article, $attributes): void {
                $article->fill($attributes)->save();
            });
        } catch (\Throwable $e) {
            if (is_string($newImagePath)) {
                Storage::disk('public')->delete($newImagePath);
            }

            throw $e;
        }

        // Replacement committed — drop the previous file if it was superseded.
        if (is_string($newImagePath) && is_string($oldImagePath) && $oldImagePath !== $newImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return $article;
    }

    private function assertSlugAvailable(string $slug, Article $article): void
    {
        $taken = Article::withTrashed()
            ->where('slug', $slug)
            ->whereKeyNot($article->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('articles.error.slug_taken'),
            ]);
        }
    }
}
