<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Update a Category. Slug uniqueness is enforced here (not a DTO attribute) so
 * one DTO serves both store and update: on update the row must keep its OWN slug,
 * so this whereKeyNot() pre-check surfaces a clash with ANY OTHER row as a 302
 * field error; the DB unique index is the TOCTOU backstop.
 */
final class UpdateCategoryAction
{
    public function handle(Category $category, CategoryData $data): Category
    {
        $slug = Str::slug($data->slug ?: $data->name);

        $this->assertSlugAvailable($slug, $category);

        return DB::transaction(function () use ($category, $data, $slug): Category {
            $category->fill([
                'name' => $data->name,
                'slug' => $slug,
                'description' => $data->description,
            ])->save();

            return $category;
        });
    }

    private function assertSlugAvailable(string $slug, Category $category): void
    {
        $taken = Category::query()
            ->where('slug', $slug)
            ->whereKeyNot($category->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('categories.error.slug_taken'),
            ]);
        }
    }
}
