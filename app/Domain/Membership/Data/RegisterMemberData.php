<?php

declare(strict_types=1);

namespace App\Domain\Membership\Data;

use App\Domain\Shared\Rules\CurpFormat;
use App\Domain\Shared\Rules\MexicanPhone;
use App\Domain\Shared\Rules\RfcFormat;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Public membership-registration payload (SPEC §3.6, §7.3 — the sole CREATE path, the
 * anonymous `POST /membership/register`).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type.
 * FormRequests are prohibited.
 *
 * This DTO is UNCONFINED-by-design: the public register path has no acting org context
 * (Decision F), so `organization_id` is an OPTIONAL applicant choice carried as-is into
 * the row (nullable, SET NULL FK). The PII fields (curp/rfc) are passed PLAIN through the
 * Action — the model's `encrypted` cast does the encryption at write. `status` and
 * `is_affiliated` are NOT in this DTO: a new registration is always Pending /
 * is_affiliated=false, stamped server-side by RegisterMemberAction.
 *
 * `date_of_birth` is a DATE-only field (the form sends YYYY-MM-DD). The global
 * data.date_format (DATE_ATOM) alone would reject a bare date, so accept both the
 * date-only and the full ATOM format here — robust at the DTO boundary (mirrors
 * OrganizationData::$registered_at).
 */
#[TypeScript]
final class RegisterMemberData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $first_name,
        #[Required, Max(100)]
        public string $last_name_paternal,
        #[Required, Max(100)]
        public string $last_name_maternal,
        #[Required]
        public string $curp,
        #[Required]
        public string $rfc,
        #[Required]
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d', \DateTimeInterface::ATOM])]
        public CarbonImmutable $date_of_birth,
        #[Required, Exists('municipalities', 'id')]
        public int $municipality_id,
        #[Required]
        public string $address,
        #[Required]
        public string $postal_code,
        #[Required, Max(100)]
        public string $neighborhood,
        #[Required]
        public string $mobile,
        #[Nullable]
        public ?string $phone = null,
        #[Nullable, Exists('organizations', 'id')]
        public ?int $organization_id = null,
    ) {}

    /**
     * PII-format rules (SPEC §11.4). The CURP/RFC/phone shared rules normalize case and
     * match the canonical pattern; postal_code is exactly 5 digits; date_of_birth is a
     * real date strictly before today (no future or same-day births).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'curp' => ['required', 'string', new CurpFormat],
            'rfc' => ['required', 'string', new RfcFormat],
            'mobile' => ['required', new MexicanPhone],
            'phone' => ['nullable', new MexicanPhone],
            'postal_code' => ['required', 'digits:5'],
            'date_of_birth' => ['required', 'date', 'before:today'],
        ];
    }
}
