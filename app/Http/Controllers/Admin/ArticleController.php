<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Content\Actions\CreateArticleAction;
use App\Domain\Content\Actions\DeleteArticleAction;
use App\Domain\Content\Actions\PublishArticleAction;
use App\Domain\Content\Actions\TransitionArticleAction;
use App\Domain\Content\Actions\UnpublishArticleAction;
use App\Domain\Content\Actions\UpdateArticleAction;
use App\Domain\Content\Data\ArchiveArticleData;
use App\Domain\Content\Data\ArticleData;
use App\Domain\Content\Data\PublishArticleData;
use App\Domain\Content\Enums\ArticleStatus;
use App\Domain\Content\Models\Article;
use App\Domain\Content\Models\Category;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Article CRUD + draft→published state transitions (SPEC §3.3 NEWS-01/02/03/07,
 * §7.2, §10.2 / Gate D). Anemic by law (≤15 lines/method): each mutation hands a
 * validated {@see ArticleData}/{@see PublishArticleData} (resolved via the method
 * signature → web failure is 302 + session errors, never 422) to its Action, which
 * owns the write, the {@see \App\Domain\Content\Services\SanitizesContent} stored-XSS
 * defense, the slug guard, and the {@see ArticleStatus} transition guard. The author
 * is read through the `Auth` facade — `Illuminate\Http\Request` is never imported
 * (controller arch law). Create/edit/CRUD are gated `role:editor`; publish/archive
 * (a manager privilege) are gated `role:manager` upstream in `routes/web.php`, so an
 * editor hitting publish is a 403 — never enforced in the domain.
 */
final class ArticleController extends Controller
{
    public function index(): Response
    {
        $status = request()->string('status')->toString() ?: null;

        $articles = Article::query()
            ->with(['category:id,name', 'author:id,name'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Article $article): array => $this->mapRow($article));

        return Inertia::render('Articles/Index', [
            'articles' => [
                'data' => $articles->items(),
                'links' => $articles->linkCollection()->toArray(),
                'meta' => [
                    'from' => $articles->firstItem(),
                    'to' => $articles->lastItem(),
                    'total' => $articles->total(),
                ],
            ],
            'categories' => $this->categoryOptions(),
            'filters' => ['status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Articles/Create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(ArticleData $data, CreateArticleAction $action): RedirectResponse
    {
        /** @var User $author */
        $author = Auth::user();

        $action->handle($data, $author);

        return redirect()->route('admin.articles.index')->with('success', __('articles.created'));
    }

    public function edit(Article $article): Response
    {
        $article->loadMissing('category:id,name');

        return Inertia::render('Articles/Edit', [
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'subtitle' => $article->subtitle,
                'content' => $article->content,
                'signature' => $article->signature,
                'featured_image_url' => $this->imageUrl($article->featured_image_path),
                'status' => $article->status->value,
                'meta_title' => $article->meta_title,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'published_at' => $article->published_at?->toIso8601String(),
            ],
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(Article $article, ArticleData $data, UpdateArticleAction $action): RedirectResponse
    {
        $action->handle($article, $data);

        return redirect()->route('admin.articles.index')->with('success', __('articles.updated'));
    }

    public function destroy(Article $article, DeleteArticleAction $action): RedirectResponse
    {
        $action->handle($article);

        return redirect()->route('admin.articles.index')->with('success', __('articles.deleted'));
    }

    public function publish(Article $article, PublishArticleData $data, PublishArticleAction $action): RedirectResponse
    {
        $action->handle($article, $data);

        return back()->with('success', __('articles.published'));
    }

    public function archive(
        Article $article,
        ArchiveArticleData $data,
        TransitionArticleAction $transition,
        UnpublishArticleAction $unpublish,
    ): RedirectResponse {
        if ($data->status === ArticleStatus::Draft) {
            $unpublish->handle($article);

            return back()->with('success', __('articles.unpublished'));
        }

        $transition->handle($article, $data->status);

        return back()->with('success', __('articles.archived'));
    }

    /**
     * Shape one paginated article row for the admin index (snake_case prop contract).
     *
     * @return array<string, mixed>
     */
    private function mapRow(Article $article): array
    {
        // category & author are NOT NULL restrict FKs (eager-loaded above).
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'status' => $article->status->value,
            'status_label_key' => $article->status->labelKey(),
            'category_name' => $article->category->name,
            'author_name' => $article->author->name,
            'published_at' => $article->published_at?->toIso8601String(),
        ];
    }

    /**
     * The category select options shared by index/create/edit (id + name only).
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return array_values(
            Category::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->all()
        );
    }

    /** Resolve a stored image path to a public URL (null stays null). */
    private function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }
}
