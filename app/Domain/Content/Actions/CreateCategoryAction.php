<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a Category. One business operation; the single write is wrapped in a
 * transaction for symmetry with the multi-table Content Actions. Slug is derived
 * from the provided slug (or the name) and uniqueness is guarded here — a 302
 * field error on a clash — instead of via a DTO Unique attribute, so the same
 * route-agnostic DTO serves both store and update.
 */
final class CreateCategoryAction
{
    public function handle(CategoryData $data): Category
    {
        $slug = Str::slug($data->slug ?: $data->name);

        $this->assertSlugAvailable($slug);

        return DB::transaction(fn (): Category => Category::create([
            'name' => $data->name,
            'slug' => $slug,
            'description' => $data->description,
        ]));
    }

    private function assertSlugAvailable(string $slug): void
    {
        if (Category::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('categories.error.slug_taken'),
            ]);
        }
    }
}
