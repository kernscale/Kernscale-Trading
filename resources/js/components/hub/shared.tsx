// Card structure adapted from Capitalio by Watermelon (https://registry.watermelon.sh/r/capitalio-dashboard.json).
import { Link } from '@inertiajs/react';
import { ArrowRight, CircleDashed } from 'lucide-react';
import type { ReactNode } from 'react';
export function Panel({
    title,
    note,
    children,
    className = '',
}: {
    title?: string;
    note?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={`hub-card ${className}`}>
            {title && (
                <header className="mb-5 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-base font-medium">{title}</h2>
                    {note && (
                        <span className="text-xs text-muted-foreground">
                            {note}
                        </span>
                    )}
                </header>
            )}
            {children}
        </section>
    );
}
export function Empty({
    title,
    children,
}: {
    title: string;
    children?: ReactNode;
}) {
    return (
        <div className="hub-empty">
            <CircleDashed className="mb-4 size-7 text-muted-foreground" />
            <h3 className="font-medium text-foreground">{title}</h3>
            {children && (
                <p className="mt-2 max-w-sm text-sm leading-6 text-muted-foreground">
                    {children}
                </p>
            )}
        </div>
    );
}
export function PageHeading({
    eyebrow,
    title,
    children,
}: {
    eyebrow: string;
    title: string;
    children?: ReactNode;
}) {
    return (
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p className="hub-eyebrow mb-2">{eyebrow}</p>
                <h1 className="text-3xl font-medium tracking-tight">{title}</h1>
            </div>
            {children}
        </div>
    );
}
export function GoLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <Link href={href} className="hub-link">
            {children}
            <ArrowRight className="size-4" />
        </Link>
    );
}
export function Status({ children }: { children: ReactNode }) {
    return <span className="hub-status">{children}</span>;
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length ? (
        <div
            role="alert"
            className="rounded-md border border-red-400/30 bg-red-400/5 p-3 text-sm text-red-300"
        >
            {Object.values(errors).join(' ')}
        </div>
    ) : null;
}
export function DataTable({
    rows,
    empty,
}: {
    rows: Record<string, unknown>[];
    empty: string;
}) {
    if (!rows.length) return <Empty title={empty} />;
    const columns = [...new Set(rows.flatMap((row) => Object.keys(row)))]
        .filter((key) => rows.some((row) => typeof row[key] !== 'object'))
        .slice(0, 7);
    return (
        <div className="overflow-auto">
            <table className="hub-table">
                <thead>
                    <tr>
                        {columns.map((key) => (
                            <th key={key}>{key.replaceAll('_', ' ')}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={displayValue(row.id, String(i))}>
                            {columns.map((key) => (
                                <td key={key}>
                                    {typeof row[key] === 'object'
                                        ? '—'
                                        : displayValue(row[key])}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function displayValue(value: unknown, fallback = '—'): string {
    return typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
        ? String(value)
        : fallback;
}
