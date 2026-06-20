<?php

declare(strict_types=1);

namespace App\Domain\Organization\Data;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Date;
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
 * Organization create/update payload (super_admin-facing).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type.
 * FormRequests are prohibited. One route-agnostic DTO serves both store and update;
 * slug uniqueness (ignore-self on update) lives in the Actions, not a DTO Unique
 * attribute. The slug is nullable here — when absent the Action derives it from the
 * name.
 *
 * Logo is required ON CREATE (SPEC §3.2 ORG-01) but optional on update (the existing
 * logo is kept). The create-vs-update distinction is made by the absence of an `id`
 * in the payload (the controller injects the bound record's id on update), via a
 * Rule::requiredIf in rules().
 */
#[TypeScript]
final class OrganizationData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $name,
        #[Required, Exists('municipalities', 'id')]
        public int $municipality_id,
        // registered_at is a DATE-only field (the form sends YYYY-MM-DD). The global
        // data.date_format (DATE_ATOM) alone would reject a bare date, so accept both
        // the date-only and the full ATOM format here — robust at the DTO boundary.
        #[Required, Date]
        #[WithCast(DateTimeInterfaceCast::class, format: ['Y-m-d', \DateTimeInterface::ATOM])]
        public CarbonImmutable $registered_at,
        #[Nullable, Max(120)]
        public ?string $slug = null,
        #[Nullable]
        public ?UploadedFile $logo = null,
    ) {}

    /**
     * Logo-required-on-create (ORG-01) + the file type/size cap. The create-vs-update
     * distinction is made by the bound route model: the update route
     * (`admin.organizations.update`) carries a bound `{organization}`, so on update the
     * logo is optional (the existing one is kept); on create no organization is bound,
     * so the logo is required. The DTO stays route-agnostic — it inspects the resolved
     * route binding, never a controller-injected field, so one DTO serves both verbs.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $isCreating = request()->route('organization') === null;

        return [
            'logo' => [
                Rule::requiredIf($isCreating),
                'nullable',
                'image',
                'mimes:jpeg,png,webp,svg',
                'max:20480',
            ],
        ];
    }
}
