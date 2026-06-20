<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Content\Actions\CreateCategoryAction;
use App\Domain\Content\Actions\DeleteCategoryAction;
use App\Domain\Content\Actions\UpdateCategoryAction;
use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Models\Category;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Category catalog CRUD (SPEC §3.3 CAT-01/02, §7.2). Mirrors the UNIGES
 * `DepartmentController`: anemic by law — each mutation hands a validated
 * {@see CategoryData} (resolved via the method signature, so a web failure is
 * 302 + session errors, never 422) to its Action, which owns the write and the
 * slug-uniqueness guard. The destroy path leans on {@see DeleteCategoryAction}'s
 * in-use pre-check, which throws {@see \App\Domain\Content\Exceptions\CategoryInUseException}
 * — rendered to a graceful 302 + `category` field error in `bootstrap/app.php`,
 * never a 500 from the restrict FK. Gated `role:editor` upstream in `routes/web.php`.
 */
final class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Categories/Index', [
            'categories' => Category::query()
                ->withCount('articles')
                ->orderBy('name')
                ->get()
                ->map(function (Category $category): array {
                    $count = $category->getAttribute('articles_count');

                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'description' => $category->description,
                        'articles_count' => is_numeric($count) ? (int) $count : 0,
                    ];
                })->all(),
        ]);
    }

    public function store(CategoryData $data, CreateCategoryAction $action): RedirectResponse
    {
        $action->handle($data);

        return back()->with('success', __('categories.created'));
    }

    public function update(Category $category, CategoryData $data, UpdateCategoryAction $action): RedirectResponse
    {
        $action->handle($category, $data);

        return back()->with('success', __('categories.updated'));
    }

    public function destroy(Category $category, DeleteCategoryAction $action): RedirectResponse
    {
        $action->handle($category);

        return back()->with('success', __('categories.deleted'));
    }
}
