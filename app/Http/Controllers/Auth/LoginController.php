<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\AuthenticateUserAction;
use App\Domain\Identity\Data\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Support\RoleLandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session authentication (SPEC §3.1 AUTH-01, §7.1).
 *
 * Anemic by law: the controller shows the login screen, delegates the credential
 * check to {@see AuthenticateUserAction} (which throws a uniform, non-enumerating
 * ValidationException keyed on `username` → 302 + session errors, never 422), and
 * routes each role to its entry screen via {@see RoleLandingRoute} — the SAME
 * mapping the deferred demo-login path will reuse. {@see LoginData} is the single
 * source of validation truth; no Form Request, no manual validation. The `Request`
 * type is never imported here (arch law): `request()` reaches the session.
 */
final class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginData $data, AuthenticateUserAction $authenticate): RedirectResponse
    {
        $user = $authenticate->handle($data);

        request()->session()->regenerate();

        return redirect()->intended(route(RoleLandingRoute::for($user->role)));
    }

    public function destroy(): RedirectResponse
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    }
}
