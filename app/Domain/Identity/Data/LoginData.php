<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Login payload (SPEC §3.1 AUTH-01, §7.1). Spatie Data is the single source of
 * validation truth + the generated TS type. Login key is `username` (not email).
 * No FormRequest, no $request->validate().
 */
#[TypeScript]
final class LoginData extends Data
{
    public function __construct(
        #[Required, Max(60)]
        public string $username,
        #[Required, Max(255)]
        public string $password,
        public bool $remember = false,
    ) {}
}
