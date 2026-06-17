<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', static fn () => Inertia::render('Landing/Index', [
    'latestArticles' => [],
    'latestJobs' => [],
]))->name('home');
