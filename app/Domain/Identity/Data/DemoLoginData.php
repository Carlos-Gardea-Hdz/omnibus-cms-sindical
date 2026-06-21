<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\DemoPreset;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Demo-login payload (SPEC §13 / AUTH-02, slice 008). Spatie Data is the single
 * source of truth: it casts the incoming `preset` string to the {@see DemoPreset}
 * enum and validates it against the enum's cases. An out-of-set value (including a
 * crafted `super_admin`) yields a web validation error keyed `preset` (302 + session
 * errors, never a 422), so the chooser can never provision an unknown persona — the
 * super_admin-exclusion invariant is enforced at the very edge.
 */
#[TypeScript]
final class DemoLoginData extends Data
{
    public function __construct(
        public DemoPreset $preset,
    ) {}
}
