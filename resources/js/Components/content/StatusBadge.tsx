import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Article status pill (SPEC §3.3 NEWS-07, §1.4). The status is the backing string
 * of App\Domain\Content\Enums\ArticleStatus ('draft' | 'published' | 'archived'),
 * arriving over the wire as a plain `status: string` prop (snake_case payloads).
 *
 * TYPE-ONLY contract: we never value-import the generated enum. We model its
 * backing values as a local string union (`ArticleStatusValue`) and compare raw
 * strings cast to that type — exactly the slice-001 UserRole badge approach. The
 * label resolves client-side via `t('article_status.<value>')` (the enum's
 * labelKey() target) and the accent mirrors ArticleStatus::color() (magenta
 * tokens). Unknown values fall back to the primary accent + the raw value.
 */
export type ArticleStatusValue = 'draft' | 'published' | 'archived';

/** Mirrors App\Domain\Content\Enums\ArticleStatus::color() (SPEC §1.4). */
const STATUS_COLOR: Record<ArticleStatusValue, string> = {
    draft: '#A03CC7',
    published: '#DD00FF',
    archived: '#9211CF',
};

interface StatusBadgeProps {
    status: string;
}

export default function StatusBadge({ status }: StatusBadgeProps) {
    const { t } = useLocale();
    const value = status as ArticleStatusValue;
    const accent = STATUS_COLOR[value] ?? '#DD00FF';
    const labelKey = `article_status.${status}`;
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
