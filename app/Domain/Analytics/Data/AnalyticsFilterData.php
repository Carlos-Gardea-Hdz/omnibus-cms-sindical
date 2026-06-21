<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Data;

use App\Domain\Analytics\Enums\TimePeriod;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The admin dashboard segmentation filter (SPEC §3.7 ANALYTICS-03). EVERY field is
 * nullable — the dashboard renders unfiltered by default. This is a READ-ONLY filter:
 * it carries NO server-owned write field (there is nothing to forge — the dashboard
 * mutates nothing), and the PUBLIC tracking path has NO DTO at all (Decision A — the
 * view is a server-derived record off the already-resolved article, not a submission).
 *
 *   - `organization_id` is meaningful only for an UNCONFINED super_admin narrowing the
 *     view to one org; a confined administrator is already org-pinned by OrganizationScope
 *     so the filter is a harmless no-op for them (the services apply it only when present).
 *   - `branch_id` / `category_id` segment the views-over-time series (via the page-view's
 *     article's branch/category).
 *   - `period` defaults to TimePeriod::default() (Month) in the controller/services when null.
 */
#[TypeScript]
final class AnalyticsFilterData extends Data
{
    public function __construct(
        #[Nullable, Exists('organizations', 'id')]
        public ?int $organization_id = null,
        #[Nullable, Exists('branches', 'id')]
        public ?int $branch_id = null,
        #[Nullable, Exists('categories', 'id')]
        public ?int $category_id = null,
        #[Nullable]
        public ?TimePeriod $period = null,
    ) {}
}
