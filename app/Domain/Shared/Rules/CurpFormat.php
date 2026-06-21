<?php

declare(strict_types=1);

namespace App\Domain\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CURP format rule (SPEC §11.4 #9). The Mexican CURP is an 18-character identifier:
 * 4 letters, 6 digits (birth date), H/M (sex), 5 letters (state + name consonants), an
 * alphanumeric homonym differentiator, and a final check digit. Value is uppercased
 * before matching so a lowercase submission is normalized rather than rejected on case.
 *
 * Shared (App\Domain\Shared) because it is domain-neutral PII validation reused by the
 * Membership RegisterMemberData DTO; it never imports a concrete domain.
 */
final class CurpFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.curp_format'));

            return;
        }

        // The D modifier (PCRE_DOLLAR_ENDONLY) makes $ reject a trailing newline,
        // so the rule guarantees its own contract without leaning on TrimStrings.
        if (preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/D', strtoupper($value)) !== 1) {
            $fail(__('validation.curp_format'));
        }
    }
}
