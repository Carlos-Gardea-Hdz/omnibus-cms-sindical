<?php

declare(strict_types=1);

namespace App\Domain\Content\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Category catalog payload (editor-facing CRUD).
 *
 * Spatie Data is the single source of truth: server-side validation rules AND
 * the TypeScript type consumed by the Inertia form. FormRequests are prohibited.
 * Slug uniqueness is enforced in the Actions (Create rejects a duplicate; Update
 * does the unique-ignore-self check) — NOT via a DTO Unique attribute that would
 * reject a row's own slug on update — so one route-agnostic DTO serves both store
 * and update.
 */
#[TypeScript]
final class CategoryData extends Data
{
    public function __construct(
        #[Required, Max(30)]
        public string $name,
        #[Nullable, Max(50)]
        public ?string $slug = null,
        #[Nullable, Max(250)]
        public ?string $description = null,
    ) {}
}
