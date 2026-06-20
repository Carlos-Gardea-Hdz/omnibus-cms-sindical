import type { ReactNode } from 'react';

/**
 * Semantic, accessible data table for the Content admin screens (slice 002).
 * Mirrors the UNIGES ReportTable contract but themed with the CMS magenta /
 * neutral tokens (slice-001 language): a rounded bordered scroll wrapper, a
 * screen-reader <caption>, a <thead> of <th scope="col">, bordered rows.
 *
 * WCAG 2.2 AA: every table carries a <caption> (sr-only, since a visible <h1>/<h2>
 * precedes it) and every header is a <th scope="col">. Generic over the row type
 * so each screen supplies its own columns + cell renderers — no `any`.
 */
export interface DataColumn<TRow> {
    /** Stable key for React + column identity. */
    key: string;
    /** Already-translated header label. */
    header: string;
    /** Cell renderer for a row. */
    cell: (row: TRow) => ReactNode;
    align?: 'left' | 'right';
    cellClassName?: string;
}

interface DataTableProps<TRow> {
    /** Accessible caption (already translated). */
    caption: string;
    columns: DataColumn<TRow>[];
    rows: TRow[];
    rowKey: (row: TRow) => string | number;
    /** Already-translated empty-state message. */
    emptyMessage: string;
}

export default function DataTable<TRow>({
    caption,
    columns,
    rows,
    rowKey,
    emptyMessage,
}: DataTableProps<TRow>) {
    if (rows.length === 0) {
        return (
            <p className="rounded-2xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-border-dark dark:text-slate-400">
                {emptyMessage}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-2xl border border-neutral-200 dark:border-border-dark">
            <table className="w-full border-collapse text-left text-sm">
                <caption className="sr-only">{caption}</caption>
                <thead className="bg-neutral-50 text-neutral-500 dark:bg-surface-dark dark:text-slate-400">
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={`px-4 py-3 font-semibold ${
                                    column.align === 'right' ? 'text-right' : ''
                                }`}
                            >
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={rowKey(row)}
                            className="border-t border-neutral-200 dark:border-border-dark"
                        >
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={`px-4 py-3 ${
                                        column.align === 'right' ? 'text-right' : ''
                                    } ${column.cellClassName ?? ''}`}
                                >
                                    {column.cell(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
