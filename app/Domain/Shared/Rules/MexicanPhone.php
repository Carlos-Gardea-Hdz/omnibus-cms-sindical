<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mexican phone format rule (SPEC §11.4). A national phone/mobile is exactly 10 digits
 * (no country code, no separators). No case normalization is needed — digits only.
 *
 * Shared (App\Domain\Shared) — domain-neutral validation reused by the Membership
 * RegisterMemberData DTO (mobile required, phone optional); it never imports a concrete
 * domain.
 */
final class MexicanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.mexican_phone'));

            return;
        }

        // D modifier (PCRE_DOLLAR_ENDONLY): $ must not match before a trailing \n.
        if (preg_match('/^\d{10}$/D', $value) !== 1) {
            $fail(__('validation.mexican_phone'));
        }
    }
}
