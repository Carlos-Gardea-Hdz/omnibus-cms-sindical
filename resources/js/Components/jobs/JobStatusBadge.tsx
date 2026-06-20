import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Job posting status pill (slice 004, JOB-01, SPEC §3.4 / §1.4). The status is the
 * backing string of App\Domain\Jobs\Enums\JobStatus
 * ('draft' | 'active' | 'paused' | 'closed'), arriving over the wire as a plain
 * `status: string` prop alongside its server-resolved `status_label_key`
 * (snake_case payloads).
 *
 * TYPE-ONLY contract: we never value-import the generated enum (that would pull a
 * value into the bundle and break the Vite build — the generated file is
 * types-only). We model its backing values as a local string union
 * (`JobStatusValue`) and mirror JobStatus::color() with the magenta palette
 * (SPEC §1.4). The label resolves client-side via `t(labelKey)` — the
 * server-supplied `status_label_key` is `job_status.<value>` (the enum's
 * labelKey() target). Unknown values fall back to the primary accent + the raw
 * value. Exactly the slice-002 StatusBadge / slice-003 ShiftBadge approach.
 */
export type JobStatusValue = 'draft' | 'active' | 'paused' | 'closed';

/** Mirrors App\Domain\Jobs\Enums\JobStatus::color() (SPEC §1.4 / CONTRACT §2). */
const STATUS_COLOR: Record<JobStatusValue, string> = {
    draft: '#A03CC7',
    active: '#DD00FF',
    paused: '#E647FF',
    closed: '#9211CF',
};

interface JobStatusBadgeProps {
    status: string;
    /** Server-supplied i18n key (`job_status.<value>`). */
    labelKey: string;
}

export default function JobStatusBadge({ status, labelKey }: JobStatusBadgeProps) {
    const { t } = useLocale();
    const accent = STATUS_COLOR[status as JobStatusValue] ?? '#DD00FF';
    const label = t(labelKey);

    return (
        <span
            className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
            style={{ backgroundColor: accent }}
        >
            {label === labelKey ? status : label}
        </span>
    );
}
