<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\LoginData;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Authenticate by username + password (SPEC §3.1 AUTH-01, §10.1). One operation.
 * Session regeneration + redirect are the controller's job; this Action stays free
 * of Illuminate\Http and returns the authenticated User (or throws a uniform,
 * non-enumerating ValidationException keyed on `username`).
 */
final class AuthenticateUserAction
{
    /**
     * @throws ValidationException single generic credential error (no enumeration)
     */
    public function handle(LoginData $data): User
    {
        $username = mb_strtolower(trim($data->username));

        $authenticated = Auth::attempt(
            ['username' => $username, 'password' => $data->password],
            $data->remember,
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
