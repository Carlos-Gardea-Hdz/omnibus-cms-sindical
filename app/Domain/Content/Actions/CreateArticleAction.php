<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\ArticleData;
use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Services\SanitizesContent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create an article as a DRAFT (SPEC §3.3 NEWS-01). The TipTap content is
 * sanitized against the §3.3 whitelist BEFORE persistence — the authoritative
 * stored-XSS gate, so the DB never holds an unsafe node. The featured image (if
 * provided) is written to disk inside the transaction's success path; on rollback
 * the freshly written file is removed so no orphaned bytes survive.
 */
final class CreateArticleAction
{
    public function __construct(
        private readonly SanitizesContent $sanitizer,
    ) {}

    public function handle(ArticleData $data, User $author): Article
    {
        $slug = Str::slug($data->slug ?: $data->title);

        $this->assertSlugAvailable($slug);

        $content = $this->sanitizer->clean($data->content);
        $imagePath = $data->featured_image?->store('articles/'.now()->format('Y/m'), 'public');

        try {
            return DB::transaction(fn (): Article => Article::create([
                'category_id' => $data->category_id,
                'author_id' => $author->getKey(),
                'title' => $data->title,
                'slug' => $slug,
                'subtitle' => $data->subtitle,
                'content' => $content,
                'signature' => $data->signature,
                'featured_image_path' => $imagePath ?: null,
                'status' => ArticleStatus::Draft,
                'meta_title' => $data->meta_title,
                'meta_description' => $data->meta_description,
                'published_at' => null,
            ]));
        } catch (\Throwable $e) {
            if (is_string($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $e;
        }
    }

    private function assertSlugAvailable(string $slug): void
    {
        if (Article::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('articles.error.slug_taken'),
            ]);
        }
    }
}
