import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Membership status pill (slice 005, SPEC §3.6 / §1.4). The status is the backing
 * string of App\Domain\Membership\Enums\MemberStatus
 * ('pending' | 'approved' | 'rejected'), arriving over the wire as a plain
 * `status: string` prop alongside its server-resolved `status_label_key`
 * (snake_case payloads).
 *
 * TYPE-ONLY contract: we never value-import the generated MemberStatus enum (that
 * would pull a value into the bundle and break the Vite build — the generated file
 * is types-only). We model its backing values as a local string union
 * (`MemberStatusValue`) and mirror MemberStatus::color() with the magenta palette
 * (SPEC §1.4 / CONTRACT §2). The label resolves client-side via `t(labelKey)` —
 * the server-supplied `status_label_key` is `member_status.<value>` (the enum's
 * labelKey() target). Unknown values fall back to the primary accent + the raw
 * value. Exactly the slice-002 StatusBadge / slice-004 JobStatusBadge approach.
 */
export type MemberStatusValue = 'pending' | 'approved' | 'rejected';

/** Mirrors App\Domain\Membership\Enums\MemberStatus::color() (CONTRACT §2). */
const STATUS_COLOR: Record<MemberStatusValue, string> = {
    pending: '#A03CC7',
    approved: '#DD00FF',
    rejected: '#9211CF',
};

interface MemberStatusBadgeProps {
    status: string;
    /** Server-supplied i18n key (`member_status.<value>`). */
    labelKey: string;
}

export default function MemberStatusBadge({ status, labelKey }: MemberStatusBadgeProps) {
    const { t } = useLocale();
    const accent = STATUS_COLOR[status as MemberStatusValue] ?? '#DD00FF';
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
