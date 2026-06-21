import type { ReactNode } from 'react';

/**
 * Single headline-metric card (slice 007, SPEC §3.7 / §7.5 / CONTRACT §10). A
 * labelled, large numeric stat (e.g. total views, active vs. closed jobs) over
 * the magenta-accented admin surface, dark/light aware. Surfaces ONLY an
 * aggregate value — never any per-visitor PII (CONTRACT §10/§11).
 *
 * WCAG 2.2 AA: the label is a real heading associated with the value, the value
 * is `tabular-nums` for stable alignment, and an optional `hint` slot carries a
 * secondary breakdown (e.g. "active / closed"). Colour is decorative; the label
 * always carries the meaning in text.
 */
interface MetricCardProps {
    /** Already-translated metric label. */
    label: string;
    /** The headline value (pre-formatted by the caller). */
    value: ReactNode;
    /** Optional already-translated secondary line. */
    hint?: ReactNode;
}

export default function MetricCard({ label, value, hint }: MetricCardProps) {
    return (
        <div className="rounded-2xl border border-neutral-200 bg-white p-6 dark:border-border-dark dark:bg-surface-dark">
            <h2 className="text-sm font-medium text-neutral-600 dark:text-slate-400">{label}</h2>
            <p className="mt-2 text-3xl font-extrabold tracking-tight tabular-nums text-gradient-primary">
                {value}
            </p>
            {hint ? (
                <p className="mt-1 text-sm text-neutral-500 tabular-nums dark:text-slate-400">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
