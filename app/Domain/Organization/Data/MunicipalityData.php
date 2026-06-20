<?php

declare(strict_types=1);

namespace App\Domain\Organization\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Municipality catalog payload (administrator-facing CRUD).
 *
 * Spatie Data is the single source of truth: server-side validation rules AND the
 * TypeScript type consumed by the Inertia form. FormRequests are prohibited. One
 * route-agnostic DTO serves both store and update.
 */
#[TypeScript]
final class MunicipalityData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public string $name,
        #[Required, Max(100)]
        public string $state,
    ) {}
}
