<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\LandingController;
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
});
