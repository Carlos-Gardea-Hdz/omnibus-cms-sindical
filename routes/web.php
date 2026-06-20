<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Public\ArticleController as PublicArticleController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

/*
 * Session authentication (SPEC §3.1 AUTH-01, §7.1). The 'guest' alias keeps an
 * already-authenticated user off the login screen; 'auth' gates logout. Web
 * validation is 302 + session errors (never 422) via the LoginData DTO, and the
 * credential error is generic (no user enumeration). Laravel ships the 'auth' and
 * 'guest' aliases by default — only the level-based 'role' alias is registered in
 * bootstrap/app.php. Brute-force throttling is DEFERRED (gate decision C).
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Admin shell (SPEC §7.2, §10.2). Gated 'auth' first (guests → 302 login), then the
 * level-based 'role' alias at the LOWEST rung ('role:editor') so all four roles
 * reach the dashboard this slice; an authenticated-but-under-level user is a 403.
 * When the Content domain lands, editor splits to a content route (gate decision D).
 */
Route::middleware(['auth', 'role:editor'])->group(function (): void {
    Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

    /*
     * Content authoring (SPEC §3.3 NEWS-01/02/03, CAT-01/02; §10.2 / Gate D).
     * Editor+ may create, edit and delete articles and manage the category catalog.
     * Publishing a draft is a HIGHER privilege (manager+) — gated separately below.
     */
    Route::get('/admin/articles', [ArticleController::class, 'index'])->name('admin.articles.index');
    Route::get('/admin/articles/create', [ArticleController::class, 'create'])->name('admin.articles.create');
    Route::post('/admin/articles', [ArticleController::class, 'store'])->name('admin.articles.store');
    Route::get('/admin/articles/{article}/edit', [ArticleController::class, 'edit'])->name('admin.articles.edit');
    Route::put('/admin/articles/{article}', [ArticleController::class, 'update'])->name('admin.articles.update');
    Route::delete('/admin/articles/{article}', [ArticleController::class, 'destroy'])->name('admin.articles.destroy');

    Route::get('/admin/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
    Route::post('/admin/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    Route::put('/admin/categories/{category}', [CategoryController::class, 'update'])->name('admin.categories.update');
    Route::delete('/admin/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');
});

/*
 * Content publishing (SPEC §3.3 NEWS-07; §10.2 / Gate D). Publish / unpublish /
 * archive a draft is a MANAGER privilege: an authenticated editor hitting these is a
 * 403 (the `role:manager` rung outranks `role:editor`). Bodyless POSTs — the publish
 * precondition (featured image + non-empty content) and the strict ArticleStatus
 * transition guard live in the Actions, not here.
 */
Route::middleware(['auth', 'role:manager'])->group(function (): void {
    Route::post('/admin/articles/{article}/publish', [ArticleController::class, 'publish'])->name('admin.articles.publish');
    Route::post('/admin/articles/{article}/archive', [ArticleController::class, 'archive'])->name('admin.articles.archive');
});

/*
 * Public single-article view (SPEC §3.3 NEWS-04). Route-model-bound by slug; renders
 * the already-sanitized TipTap content. views_count tracking is DEFERRED (Analytics).
 */
Route::get('/articles/{article:slug}', [PublicArticleController::class, 'show'])->name('articles.show');
