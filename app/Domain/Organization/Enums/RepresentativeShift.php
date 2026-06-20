<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Representative work shift (SPEC §3.2 ORG-03). Backed enum — the single source of
 * truth for the `representatives.shift` column; cast in the Representative model so
 * no magic strings ever reach a query or a payload.
 */
#[TypeScript]
enum RepresentativeShift: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Night = 'night';

    /** i18n key resolved client-side and via __() server-side: representative_shift.morning, etc. */
    public function labelKey(): string
    {
        return 'representative_shift.'.$this->value;
    }

    /** Magenta-palette accent per shift (SPEC §1.4) for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Morning => '#E647FF',  // primary-light
            self::Evening => '#DD00FF',  // primary
            self::Night => '#9211CF',    // primary-dark
        };
    }
}
