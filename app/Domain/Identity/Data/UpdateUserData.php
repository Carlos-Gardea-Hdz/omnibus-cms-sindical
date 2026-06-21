<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\UserRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Confirmed;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * User-update payload (SPEC §3.1 / AUTH-04, slice 008). Same shape as {@see UserData}
 * with two differences:
 *
 *   - `password` is NULLABLE: a blank password means "leave the current hash"; a
 *     present password must still be ≥8 and Confirmed (with `password_confirmation`).
 *   - `username` / `email` uniqueness IGNORES the row being edited — so re-saving a
 *     user without changing its own username/email does not trip the unique index.
 *     The ignored id is read from the route's `{user}` binding in {@see self::rules()}
 *     (the column-attribute `Unique` cannot express ignore-self route-aware), keeping
 *     the DTO the single source of validation truth.
 *
 * The actor-dependent guards (no self-elevation, the super_admin own-record rule, the
 * assignable-set) live in UpdateUserAction, not here — they depend on the acting user.
 */
#[TypeScript]
final class UpdateUserData extends Data
{
    public function __construct(
        #[Required, Max(60)]
        public string $username,
        #[Nullable, Max(100)]
        public ?string $name,
        #[Nullable, Email, Max(255)]
        public ?string $email,
        #[Nullable, Min(8), Confirmed]
        public ?string $password,
        public UserRole $role,
        #[Nullable]
        public ?int $organization_id = null,
    ) {}

    /**
     * Route-aware unique-ignore-self rules for username/email. The `{user}` route
     * binding supplies the id to ignore; on a non-route validation path (none today)
     * the ignore is simply absent and the rule behaves as a plain unique.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $userId = request()->route('user');
        $ignore = is_object($userId) && method_exists($userId, 'getKey')
            ? $userId->getKey()
            : $userId;

        return [
            'username' => ['required', 'string', 'max:60', Rule::unique('users', 'username')->ignore($ignore)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignore)],
        ];
    }
}
