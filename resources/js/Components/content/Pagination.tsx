import { Link } from '@inertiajs/react';
import { useLocale } from '@/Contexts/LocaleContext';

/**
 * Laravel paginator nav, extracted from the slice-002 Articles/Index markup so the
 * org-domain paginated lists (Organizations / Branches / Representatives) don't
 * duplicate it. Renders nothing when there is only one page (≤3 links: prev,
 * single page, next).
 *
 * Pagination labels are SERVER-GENERATED, trusted strings (Laravel's paginator
 * emits "&laquo; Previous" etc. as HTML entities) — NOT user content.
 * dangerouslySetInnerHTML here only decodes those entities; it never touches any
 * user-supplied data.
 */
export interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationProps {
    links: PaginatorLink[];
}

export default function Pagination({ links }: PaginationProps) {
    const { t } = useLocale();

    if (links.length <= 3) {
        return null;
    }

    return (
        <nav
            aria-label={t('content.pagination')}
            className="mt-6 flex flex-wrap items-center justify-center gap-1"
        >
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={`inline-flex h-9 min-w-9 items-center justify-center rounded-md px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none ${
                            link.active
                                ? 'bg-primary text-white'
                                : 'border border-neutral-300 text-neutral-700 hover:border-primary hover:text-primary dark:border-border-dark dark:text-slate-300'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={index}
                        aria-hidden="true"
                        className="inline-flex h-9 min-w-9 items-center justify-center rounded-md px-3 text-sm text-neutral-400 dark:text-slate-600"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
