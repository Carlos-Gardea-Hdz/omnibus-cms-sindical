<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin shell entry screen (SPEC §7.2). The post-login landing for every role this
 * slice (the ladder gate at `role:editor` lets all four through). Anemic: it reads
 * the authenticated user and renders the page with the exact snake_case prop
 * contract the Inertia page expects. `Auth::user()` (facade) reaches the user — the
 * `Illuminate\Http\Request` type is never imported here (controller arch law).
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        return Inertia::render('Admin/Dashboard', [
            'username' => $user->username,
            'role' => $user->role->value,
            'role_label_key' => $user->role->labelKey(),
        ]);
    }
}
