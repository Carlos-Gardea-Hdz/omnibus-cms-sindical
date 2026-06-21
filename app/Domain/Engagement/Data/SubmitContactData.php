<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Data;

use App\Domain\Shared\Rules\MexicanPhone;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Public contact-form payload (SPEC §3.5 CONTACT-01, §7.1 — the anonymous
 * `POST /contact`, the sole CREATE path).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type.
 * FormRequests are prohibited.
 *
 * This DTO is UNCONFINED-by-design and carries NO server-owned field: the
 * `contact_messages` table has no `status` / moderation column (Decision B), so there is
 * nothing for the anonymous submitter to forge or self-elevate — unknown payload keys are
 * discarded by Spatie Data and the Action writes only the defined fields. Both
 * `organization_id` AND `branch_id` are anonymous applicant choices carried as-is; their
 * EXISTENCE is enforced here (Exists), but the branch→org CONSISTENCY invariant is
 * asserted in SubmitContactAction (the slice-004 trait), NOT here — the DTO is
 * route-agnostic and a single Exists check cannot cross-reference two columns.
 *
 * `message` is PLAIN TEXT (Decision A) — NOT a TipTap document; React auto-escapes it at
 * render, never dangerouslySetInnerHTML, so NO SanitizesContent. `phone` is exactly 10
 * digits via the shared {@see MexicanPhone} rule (Decision I — REUSED from slice-005),
 * declared in rules() because it is a rule object, not an attribute. email is ≤60 to match
 * the column width and §3.5 CONTACT-01.
 */
#[TypeScript]
final class SubmitContactData extends Data
{
    public function __construct(
        #[Required, Max(60)]
        public string $first_name,
        #[Required, Max(60)]
        public string $last_name,
        #[Required, Email, Max(60)]
        public string $email,
        #[Required]
        public string $phone,
        #[Required, Max(1000)]
        public string $message,
        #[Required, Exists('organizations', 'id')]
        public int $organization_id,
        #[Required, Exists('branches', 'id')]
        public int $branch_id,
    ) {}

    /**
     * The 10-digit phone rule (SPEC §3.5 CONTACT-01 — exactly 10 digits). The shared
     * {@see MexicanPhone} rule is a rule OBJECT (not a Spatie attribute), so it is
     * declared here. No other field needs an override — the attributes carry the rest.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'phone' => ['required', new MexicanPhone],
        ];
    }
}
