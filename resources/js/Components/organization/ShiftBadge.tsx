import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Representative shift pill (slice 003, ORG-03, SPEC §1.4). The shift is the
 * backing string of App\Domain\Organization\Enums\RepresentativeShift
 * ('morning' | 'evening' | 'night'), arriving over the wire as a plain
 * `shift: string` prop alongside its server-resolved `shift_label_key`
 * (snake_case payloads).
 *
 * TYPE-ONLY contract: we never value-import the generated enum (that would pull a
 * value into the bundle and break the Vite build — the generated file is
 * types-only). We model its backing values as a local string union
 * (`ShiftValue`) and mirror RepresentativeShift::color() with the magenta palette
 * (SPEC §1.4). The label resolves client-side via `t(labelKey)` — the
 * server-supplied `shift_label_key` is `representative_shift.<value>` (the enum's
 * labelKey() target). Unknown values fall back to the primary accent + the raw
 * value.
 */
export type ShiftValue = 'morning' | 'evening' | 'night';

/** Mirrors App\Domain\Organization\Enums\RepresentativeShift::color() (SPEC §1.4). */
const SHIFT_COLOR: Record<ShiftValue, string> = {
    morning: '#DD00FF',
    evening: '#A03CC7',
    night: '#9211CF',
};

interface ShiftBadgeProps {
    shift: string;
    /** Server-supplied i18n key (`representative_shift.<value>`). */
    labelKey: string;
}

export default function ShiftBadge({ shift, labelKey }: ShiftBadgeProps) {
    const { t } = useLocale();
    const accent = SHIFT_COLOR[shift as ShiftValue] ?? '#DD00FF';
    const label = t(labelKey);

    return (
        <span
            className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
            style={{ backgroundColor: accent }}
        >
            {label === labelKey ? shift : label}
        </span>
    );
}
