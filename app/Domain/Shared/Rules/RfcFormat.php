<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RFC format rule (SPEC §11.4 #10). The Mexican RFC is 12 (moral) or 13 (física)
 * characters: 3–4 letters (the leading 4-letter form for personas físicas, 3 for
 * morales, with `&`/`N` permitted in the alpha block), 6 digits (date), and a 3-char
 * alphanumeric homonym/check block. Value is uppercased before matching so a lowercase
 * submission is normalized rather than rejected on case.
 *
 * Shared (App\Domain\Shared) — domain-neutral PII validation reused by the Membership
 * RegisterMemberData DTO; it never imports a concrete domain.
 */
final class RfcFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.rfc_format'));

            return;
        }

        // D modifier (PCRE_DOLLAR_ENDONLY): $ must not match before a trailing \n.
        if (preg_match('/^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$/D', strtoupper($value)) !== 1) {
            $fail(__('validation.rfc_format'));
        }
    }
}
