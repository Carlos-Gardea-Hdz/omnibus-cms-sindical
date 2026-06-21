<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\UserRole;
use Spatie\LaravelData\Attributes\Validation\Confirmed;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * User-create payload (SPEC §3.1 / AUTH-04, slice 008). Spatie Data is the single
 * source of truth: server-side validation rules AND the generated TS type — no
 * FormRequest, no $request->validate().
 *
 * - `username` is the login key: required, unique, ≤60 (mirrors the column).
 * - `name` / `email` are NULLABLE (the slice-008 migration relaxes both columns); a
 *   present email must be a unique, well-formed address.
 * - `password` is required, ≥8, and Confirmed — the payload MUST carry a matching
 *   `password_confirmation`; it is set via the model's `hashed` cast, never logged.
 * - `role` is validated as a UserRole by Spatie's enum cast; the ASSIGNABLE-set check
 *   (actor-dependent — an administrator may not mint a super_admin) lives in
 *   CreateUserAction, not here, because it depends on the acting user.
 * - `organization_id` is honoured ONLY for a super_admin actor; for an administrator
 *   the Action OVERWRITES it from the OrganizationContext (server-side tenant stamp),
 *   so a confined actor can never plant a user in another org via the payload.
 */
#[TypeScript]
final class UserData extends Data
{
    public function __construct(
        #[Required, Max(60), Unique('users', 'username')]
        public string $username,
        #[Nullable, Max(100)]
        public ?string $name,
        #[Nullable, Email, Max(255), Unique('users', 'email')]
        public ?string $email,
        #[Required, Min(8), Confirmed]
        public string $password,
        public UserRole $role,
        #[Nullable]
        public ?int $organization_id = null,
    ) {}
}
