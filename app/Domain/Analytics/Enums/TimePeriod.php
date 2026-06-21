<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The dashboard segmentation time-bucket (SPEC §3.7 ANALYTICS-03 "filter by … time
 * period day/week/month"). A backed (string) enum so there are NO magic strings in the
 * `date_trunc(?, …)` aggregate calls — the metric services bind `$period->truncUnit()`,
 * whose backing value maps 1:1 to the Postgres `date_trunc` unit ('day'|'week'|'month').
 *
 * `labelKey()` resolves the server i18n key (analytics.period.{value}, present in BOTH
 * lang/es.json + lang/en.json); the dashboard's other copy lives in resources/locales
 * (frontend t()). `default()` is the no-filter fallback (Month).
 */
#[TypeScript]
enum TimePeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The Postgres `date_trunc` unit — bound as a parameter, never inlined as a magic string. */
    public function truncUnit(): string
    {
        return $this->value;
    }

    /** Server i18n key (lang/*.json): analytics.period.day | .week | .month. */
    public function labelKey(): string
    {
        return "analytics.period.{$this->value}";
    }

    /** The no-filter fallback bucket for the dashboard. */
    public static function default(): self
    {
        return self::Month;
    }
}
