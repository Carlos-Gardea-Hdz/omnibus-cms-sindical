<?php

declare(strict_types=1);

namespace App\Domain\Organization\Data;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Director create/update payload (administrator-facing).
 *
 * Spatie Data is the single source of truth: server-side rules + the TS type. The
 * one-director-per-org uniqueness is asserted in CreateDirectorAction (the DB unique
 * index on directors.organization_id is the backstop), NOT a DTO attribute, so one
 * route-agnostic DTO serves both store and update. The photo is an optional image.
 */
#[TypeScript]
final class DirectorData extends Data
{
    public function __construct(
        #[Required, Exists('organizations', 'id')]
        public int $organization_id,
        #[Required, Max(100)]
        public string $first_name,
        #[Required, Max(100)]
        public string $last_name,
        #[Nullable]
        public ?UploadedFile $photo = null,
    ) {}

    /**
     * The photo file type/size cap (a global constraint Spatie's attribute layer
     * cannot fully express). Optional — a director may carry no photo.
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
