import { router } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';
import AdminShell from '@/Components/AdminShell';
import DataTable, { type DataColumn } from '@/Components/content/DataTable';
import MetricCard from '@/Components/analytics/MetricCard';
import BarSeries, { type BarDatum } from '@/Components/analytics/BarSeries';

/**
 * Org-scoped analytics dashboard (slice 007 — the LAST CMS domain; SPEC §3.7 /
 * §7.5 / §10.2 / CONTRACT §8/§10). READ-ONLY: a single index screen, NO
 * mutations, NO export (deferred). Gated server-side at
 * ['auth','role:administrator','org.scope'] (CONTRACT §0) — a confined
 * administrator sees ONLY their own org's aggregates (auto-scoped by
 * OrganizationScope); super_admin sees all and can narrow via the organization
 * filter. manager/editor get a 403 and never reach this page.
 *
 * Every number on this page is an AGGREGATE computed server-side in ONE grouped
 * query per metric (CONTRACT §7). The wire carries ONLY aggregates — never an
 * `ip_hash` / `user_agent` / any per-visitor PII (CONTRACT §10/§11).
 *
 * Props are snake_case and match Admin\AnalyticsController::index EXACTLY
 * (CONTRACT §10 — the prop-contract test pins this shape):
 *   metrics: { total_views, top_articles[], views_over_time[], jobs{active,closed},
 *              member_trend[], contact_trend[] }
 *   filters: { organization_id, branch_id, category_id, period }
 *   options: { organizations[], branches[], categories[] }
 *
 * The filter form is a plain GET reload (router.get with query params,
 * preserveState) — no client mutation, no useForm. `period` mirrors the backed
 * enum App\Domain\Analytics\Enums\TimePeriod ('day' | 'week' | 'month'); we model
 * it as a local string union (TYPE-ONLY contract — never value-import the
 * generated enum, which would pull a value into the bundle and break the build).
 * Magenta theme, dark/light, bilingual via the locale hook.
 */
type TimePeriodValue = 'day' | 'week' | 'month';

const PERIODS: TimePeriodValue[] = ['day', 'week', 'month'];

interface TopArticleRow {
    id: number;
    title: string;
    views_count: number;
}

interface Option {
    id: number;
    name: string;
}

interface AnalyticsIndexProps {
    metrics: {
        total_views: number;
        top_articles: TopArticleRow[];
        views_over_time: BarDatum[];
        jobs: { active: number; closed: number };
        member_trend: BarDatum[];
        contact_trend: BarDatum[];
    };
    filters: {
        organization_id: number | null;
        branch_id: number | null;
        category_id: number | null;
        period: TimePeriodValue;
    };
    options: {
        organizations: Option[];
        branches: Option[];
        categories: Option[];
    };
}

const BASE_ROUTE = '/admin/analytics';

export default function AnalyticsIndex({ metrics, filters, options }: AnalyticsIndexProps) {
    const { t, locale } = useLocale();

    const formatNumber = (value: number): string =>
        new Intl.NumberFormat(locale === 'es' ? 'es-MX' : 'en-US').format(value);

    // Apply a single filter delta over a GET reload, dropping nulled-out keys so
    // the query string stays clean. The server re-derives everything; no state is
    // held client-side.
    const applyFilter = (patch: Partial<AnalyticsIndexProps['filters']>) => {
        const next: Record<string, string> = {};
        const merged = { ...filters, ...patch };
        if (merged.organization_id != null) {
            next.organization_id = String(merged.organization_id);
        }
        if (merged.branch_id != null) {
            next.branch_id = String(merged.branch_id);
        }
        if (merged.category_id != null) {
            next.category_id = String(merged.category_id);
        }
        next.period = merged.period;
        router.get(BASE_ROUTE, next, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    // super_admin is the only role that receives a non-empty organizations list
    // (a confined administrator is auto-scoped server-side, CONTRACT §10).
    const showOrganizationFilter = options.organizations.length > 0;

    const topArticleColumns: DataColumn<TopArticleRow>[] = [
        {
            key: 'title',
            header: t('analytics.top.col.title'),
            cell: (row) => <span className="font-medium">{row.title}</span>,
        },
        {
            key: 'views_count',
            header: t('analytics.top.col.views'),
            align: 'right',
            cell: (row) => (
                <span className="font-semibold tabular-nums text-primary">
                    {formatNumber(row.views_count)}
                </span>
            ),
        },
    ];

    const renderSelect = (
        labelKey: string,
        allLabelKey: string,
        value: number | null,
        items: Option[],
        onChange: (next: number | null) => void,
    ) => {
        const selectId = `analytics-filter-${labelKey}`;
        return (
            <div className="flex flex-col">
                <label
                    htmlFor={selectId}
                    className="mb-1 text-sm font-medium text-neutral-800 dark:text-slate-200"
                >
                    {t(labelKey)}
                </label>
                <select
                    id={selectId}
                    value={value == null ? '' : String(value)}
                    onChange={(event) =>
                        onChange(event.target.value === '' ? null : Number(event.target.value))
                    }
                    className="h-11 rounded-lg border border-neutral-300 bg-white px-3 text-neutral-900 transition-colors focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none dark:border-border-dark dark:bg-surface-dark dark:text-slate-100"
                >
                    <option value="">{t(allLabelKey)}</option>
                    {items.map((item) => (
                        <option key={item.id} value={String(item.id)}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
        );
    };

    return (
        <AdminShell
            title={t('analytics.title')}
            subtitle={t('analytics.subtitle')}
            backHref="/admin/dashboard"
            backLabel={t('content.back_to_dashboard')}
        >
            {/* Filter bar — GET-reloading; period is always present, the rest optional. */}
            <form
                className="mb-8 grid gap-4 rounded-2xl border border-neutral-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4 dark:border-border-dark dark:bg-surface-dark"
                onSubmit={(event) => event.preventDefault()}
                aria-label={t('analytics.filter.label')}
            >
                <div className="flex flex-col">
                    <label
                        htmlFor="analytics-filter-period"
                        className="mb-1 text-sm font-medium text-neutral-800 dark:text-slate-200"
                    >
                        {t('analytics.filter.period')}
                    </label>
                    <select
                        id="analytics-filter-period"
                        value={filters.period}
                        onChange={(event) =>
                            applyFilter({ period: event.target.value as TimePeriodValue })
                        }
                        className="h-11 rounded-lg border border-neutral-300 bg-white px-3 text-neutral-900 transition-colors focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none dark:border-border-dark dark:bg-surface-dark dark:text-slate-100"
                    >
                        {PERIODS.map((period) => (
                            <option key={period} value={period}>
                                {t(`analytics.period.${period}`)}
                            </option>
                        ))}
                    </select>
                </div>

                {showOrganizationFilter
                    ? renderSelect(
                          'analytics.filter.organization',
                          'analytics.filter.all_organizations',
                          filters.organization_id,
                          options.organizations,
                          (next) => applyFilter({ organization_id: next }),
                      )
                    : null}

                {renderSelect(
                    'analytics.filter.branch',
                    'analytics.filter.all_branches',
                    filters.branch_id,
                    options.branches,
                    (next) => applyFilter({ branch_id: next }),
                )}

                {renderSelect(
                    'analytics.filter.category',
                    'analytics.filter.all_categories',
                    filters.category_id,
                    options.categories,
                    (next) => applyFilter({ category_id: next }),
                )}
            </form>

            {/* Headline metric cards. */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <MetricCard
                    label={t('analytics.metric.total_views')}
                    value={formatNumber(metrics.total_views)}
                />
                <MetricCard
                    label={t('analytics.metric.jobs')}
                    value={
                        <>
                            {formatNumber(metrics.jobs.active)}
                            <span className="mx-1 text-neutral-400 dark:text-slate-500">/</span>
                            {formatNumber(metrics.jobs.closed)}
                        </>
                    }
                    hint={t('analytics.metric.jobs_hint')}
                />
                <MetricCard
                    label={t('analytics.metric.top_count')}
                    value={formatNumber(metrics.top_articles.length)}
                    hint={t('analytics.metric.top_count_hint')}
                />
            </div>

            {/* Time-series bar charts (CSS bars, no charting lib — Decision G). */}
            <div className="mt-8 grid gap-4 lg:grid-cols-3">
                <BarSeries
                    title={t('analytics.chart.views_over_time')}
                    data={metrics.views_over_time}
                    emptyMessage={t('analytics.chart.empty')}
                />
                <BarSeries
                    title={t('analytics.chart.member_trend')}
                    data={metrics.member_trend}
                    emptyMessage={t('analytics.chart.empty')}
                />
                <BarSeries
                    title={t('analytics.chart.contact_trend')}
                    data={metrics.contact_trend}
                    emptyMessage={t('analytics.chart.empty')}
                />
            </div>

            {/* Top-N articles by view count. */}
            <section className="mt-8">
                <h2 className="mb-4 text-lg font-bold tracking-tight">
                    {t('analytics.top.title')}
                </h2>
                <DataTable
                    caption={t('analytics.top.title')}
                    columns={topArticleColumns}
                    rows={metrics.top_articles}
                    rowKey={(row) => row.id}
                    emptyMessage={t('analytics.top.empty')}
                />
            </section>
        </AdminShell>
    );
}
