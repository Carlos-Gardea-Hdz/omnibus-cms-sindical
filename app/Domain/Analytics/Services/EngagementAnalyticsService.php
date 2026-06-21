<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Enums\TimePeriod;
use App\Domain\Engagement\Models\ContactMessage;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\JobPosting;
use App\Domain\Membership\Models\Member;

/**
 * EngagementAnalyticsService (SPEC §3.7 ANALYTICS-02 "Minimum Viable Metrics" — Jobs
 * active-vs-closed, Members registration trend, Contact messages per period). Mirrors
 * the UNIGES TerminalEfficiencyService: ONE grouped/filtered aggregate per metric,
 * parameterized enum bindings in the `count(*) filter (where status = ?)` (no magic
 * strings), every aggregate mixed-cast-guarded, NEVER withoutGlobalScope — the global
 * OrganizationScope on JobPosting/Member/ContactMessage IS the org isolation. The
 * optional $organizationId narrows an unconfined super_admin to one org; for a confined
 * administrator it is a harmless extra predicate. No write, no transaction,
 * Illuminate\Http-free. date_trunc units come from the TimePeriod enum, bound as params.
 */
final class EngagementAnalyticsService
{
    /**
     * Active vs closed job postings — ONE query, two parameterized filtered counts.
     *
     * @return array{active:int,closed:int}
     */
    public function jobsActiveVsClosed(?int $organizationId = null): array
    {
        $row = JobPosting::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('count(*) filter (where status = ?) as active', [JobStatus::Active->value])
            ->selectRaw('count(*) filter (where status = ?) as closed', [JobStatus::Closed->value])
            ->first();

        return [
            'active' => $row === null ? 0 : $this->toInt($row->getAttribute('active')),
            'closed' => $row === null ? 0 : $this->toInt($row->getAttribute('closed')),
        ];
    }

    /**
     * Member registrations per date_trunc bucket — ONE grouped query over scoped Member.
     *
     * @return list<array{bucket:string,total:int}>
     */
    public function memberRegistrationTrend(TimePeriod $period, ?int $organizationId = null): array
    {
        $rows = Member::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('date_trunc(?, created_at)::date as bucket', [$period->truncUnit()])
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $this->mapBuckets($rows);
    }

    /**
     * Contact messages per date_trunc bucket — ONE grouped query over scoped ContactMessage.
     *
     * @return list<array{bucket:string,total:int}>
     */
    public function contactMessagesPerPeriod(TimePeriod $period, ?int $organizationId = null): array
    {
        $rows = ContactMessage::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw('date_trunc(?, created_at)::date as bucket', [$period->truncUnit()])
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $this->mapBuckets($rows);
    }

    /**
     * Shape a grouped date_trunc result set into the {bucket,total} list, mixed-cast-guarding
     * every aggregate. The foreach accumulation yields a guaranteed list (PHPStan L10).
     *
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @return list<array{bucket:string,total:int}>
     */
    private function mapBuckets(iterable $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'bucket' => $this->toStringValue($row->getAttribute('bucket')),
                'total' => $this->toInt($row->getAttribute('total')),
            ];
        }

        return $result;
    }

    /**
     * Postgres aggregates (count(*), count(*) filter, date_trunc) arrive as `mixed` via
     * the magic attribute accessor; guard before casting (PHPStan L10 — never `(int)` on
     * raw mixed).
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toStringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
