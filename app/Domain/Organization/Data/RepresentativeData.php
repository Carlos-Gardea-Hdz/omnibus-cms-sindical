<?php

declare(strict_types=1);

namespace App\Domain\Organization\Data;

use App\Domain\Organization\Enums\RepresentativeShift;
use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Representative create/update payload (manager-facing).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type. `shift`
 * resolves to the RepresentativeShift backed enum (a bad value is a 302 validation
 * error, never a magic string reaching the DB). is_coordinator defaults false. The
 * photo is an optional image. One route-agnostic DTO serves both store and update.
 *
 * organization_id / branch_id carry only Exists(...) here — the DTO is route-agnostic and
 * does NOT enforce the tenant invariant. The Create/Update Representative Actions resolve
 * the effective organization_id server-side from the OrganizationContext (forcing the
 * confined org) and assert the chosen branch belongs to it, mirroring the Article
 * author-stamp pattern.
 */
#[TypeScript]
final class RepresentativeData extends Data
{
    public function __construct(
        #[Required, Exists('organizations', 'id')]
        public int $organization_id,
        #[Required, Exists('branches', 'id')]
        public int $branch_id,
        #[Required, Max(100)]
        public string $first_name,
        #[Required, Max(100)]
        public string $last_name,
        #[Required]
        public RepresentativeShift $shift,
        public bool $is_coordinator = false,
        #[Nullable]
        public ?UploadedFile $photo = null,
    ) {}

    /**
     * The photo file type/size cap. Optional — a representative may carry no photo.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:20480'],
        ];
    }
}
