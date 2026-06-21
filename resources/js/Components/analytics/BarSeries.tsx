import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Lightweight time-series bar chart (slice 007, SPEC §3.7 / §7.5 / CONTRACT §10).
 * No charting npm dependency (Decision G — pnpm supply-chain hygiene): the bars
 * are plain CSS-height columns over a shared baseline, sized relative to the
 * largest bucket in the series. Mirrors the slice-008-UNIGES PercentageBar
 * approach (the fill height is the ONLY dynamic style; track + fill colours are
 * static literal classes so Tailwind v4 keeps them in the build).
 *
 * Each datum is { bucket, total } — `bucket` is a server-truncated date string
 * (date_trunc(period, …)::date, e.g. "2026-06-01"), `total` an aggregate count.
 * Both already org-scoped server-side; this component only renders aggregates,
 * never any per-visitor PII (CONTRACT §10).
 *
 * WCAG 2.2 AA: rendered as an accessible data table fallback is overkill for a
 * minimal MVP, so each column is a real labelled element — the visible numeric
 * value sits beside the bar (the height is a redundant cue, never the only
 * signal), the column carries an aria-label of "label: N", and an empty series
 * shows a localized empty state instead of a blank canvas. Magenta fill, dark/
 * light aware.
 */
export interface BarDatum {
    bucket: string;
    total: number;
}

interface BarSeriesProps {
    /** Already-translated chart title (rendered as the section heading). */
    title: string;
    data: BarDatum[];
    /** Already-translated empty-state copy. */
    emptyMessage: string;
}

export default function BarSeries({ title, data, emptyMessage }: BarSeriesProps) {
    const { locale } = useLocale();

    const max = data.reduce((acc, datum) => Math.max(acc, datum.total), 0);

    const formatBucket = (bucket: string): string => {
        const parsed = new Date(bucket);
        if (Number.isNaN(parsed.getTime())) {
            return bucket;
        }
        return parsed.toLocaleDateString(locale === 'es' ? 'es-MX' : 'en-US', {
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <section className="rounded-2xl border border-neutral-200 bg-white p-6 dark:border-border-dark dark:bg-surface-dark">
            <h2 className="text-sm font-semibold tracking-wide text-neutral-700 uppercase dark:text-slate-300">
                {title}
            </h2>

            {data.length === 0 ? (
                <p className="mt-6 text-sm text-neutral-500 dark:text-slate-400">{emptyMessage}</p>
            ) : (
                <div
                    role="img"
                    aria-label={title}
                    className="mt-6 flex h-44 items-end gap-2 overflow-x-auto"
                >
                    {data.map((datum) => {
                        // Height relative to the tallest bucket; a non-zero total
                        // always shows a minimum sliver so it is never invisible.
                        const ratio = max > 0 ? datum.total / max : 0;
                        const heightPct = datum.total > 0 ? Math.max(4, ratio * 100) : 0;
                        return (
                            <div
                                key={datum.bucket}
                                className="flex min-w-10 flex-1 flex-col items-center gap-2"
                                aria-label={`${formatBucket(datum.bucket)}: ${datum.total}`}
                            >
                                <span className="text-xs font-semibold tabular-nums text-neutral-700 dark:text-slate-200">
                                    {datum.total}
                                </span>
                                <div className="flex h-28 w-full items-end">
                                    <div
                                        className="w-full rounded-t-md bg-primary transition-[height] dark:bg-primary-light"
                                        style={{ height: `${heightPct}%` }}
                                    />
                                </div>
                                <span className="text-[11px] whitespace-nowrap text-neutral-500 tabular-nums dark:text-slate-400">
                                    {formatBucket(datum.bucket)}
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
