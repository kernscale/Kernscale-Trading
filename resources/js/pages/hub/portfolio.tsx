import NativeDashboard from '@/components/capitalio/native-dashboard';
import NativeAnalytics from '@/components/capitalio/native-analytics';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DataTable,
    Empty,
    Errors,
    displayValue,
    Panel,
} from '@/components/hub/shared';
import { Quiet, Segment, StatusMark } from '@/components/kit/micro';
import type { PortfolioSnapshot } from '@/types/hub';
type Watchlist = { id: number; symbol: string };
export default function Portfolio({
    snapshot: receivedSnapshot = null,
    freshness,
    watchlists = [],
}: {
    snapshot?: PortfolioSnapshot | null;
    freshness?: { stale: boolean; observed_at: string | null };
    watchlists?: Watchlist[];
}) {
    const snapshot =
        receivedSnapshot?.source === 'native-paper-ledger'
            ? receivedSnapshot
            : null;
    const analytics = usePage().url.includes('view=analytics');
    const [tab, setTab] = useState<
        | 'holdings'
        | 'accounts'
        | 'activities'
        | 'risk'
        | 'watchlists'
        | 'history'
    >('holdings');
    const form = useForm({ symbol: '' });
    return (
        <>
            <Head title="Portfolio" />
            {analytics ? (
                <NativeAnalytics snapshot={snapshot} />
            ) : (
                <NativeDashboard snapshot={snapshot} />
            )}
            <section className="flex flex-col gap-4 px-4 pb-8 md:px-8">
                <div>
                    <span className="kit-pill">
                        <StatusMark
                            status={
                                snapshot
                                    ? freshness?.stale
                                        ? 'waiting'
                                        : 'available'
                                    : 'idle'
                            }
                            size={11}
                        />
                        {snapshot
                            ? freshness?.stale
                                ? 'Snapshot is stale'
                                : 'Snapshot available'
                            : 'Not connected'}
                    </span>
                </div>
                <Panel
                    title="Account overview"
                    note={snapshot?.observed_at ?? 'No verified snapshot'}
                >
                    {snapshot ? (
                        <>
                            <p className="mb-6 text-xs text-muted-foreground">
                                Source: {snapshot.source} · Observed{' '}
                                {snapshot.observed_at}
                            </p>
                            <div className="mb-7 grid gap-4 sm:grid-cols-3">
                                {Object.entries(snapshot.metrics ?? {})
                                    .filter(
                                        ([, value]) =>
                                            typeof value !== 'object',
                                    )
                                    .map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="rounded-xl border border-[var(--kit-line)] bg-[var(--kit-surface)] p-4"
                                        >
                                            <p className="text-xs text-muted-foreground capitalize">
                                                {key.replaceAll('_', ' ')}
                                            </p>
                                            <p className="mt-3 text-2xl font-medium tabular-nums">
                                                {displayValue(
                                                    value,
                                                    'Unavailable',
                                                )}
                                            </p>
                                        </div>
                                    ))}
                            </div>
                        </>
                    ) : (
                        <Quiet className="mb-6">
                            The shared native paper account is the source of
                            truth. A missing connection never becomes a zero
                            balance or synthetic performance.
                        </Quiet>
                    )}
                    <Segment
                        className="mb-5"
                        label="Portfolio data"
                        value={tab}
                        onChange={setTab}
                        options={[
                            { value: 'holdings', label: 'Holdings' },
                            { value: 'accounts', label: 'Accounts' },
                            { value: 'activities', label: 'Activities' },
                            { value: 'risk', label: 'Risk' },
                            {
                                value: 'watchlists',
                                label: 'Watchlists',
                                count: watchlists.length,
                            },
                            { value: 'history', label: 'Performance history' },
                        ]}
                    />
                    {tab === 'watchlists' ? (
                        <>
                            <p className="mb-4 text-xs text-muted-foreground">
                                Your saved symbols are private to your account.
                                Saving a symbol does not place an order.
                            </p>
                            <form
                                className="mb-5 flex flex-wrap gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post('/portfolio/watchlists', {
                                        onSuccess: () => form.reset(),
                                    });
                                }}
                            >
                                <input
                                    aria-label="Watchlist symbol"
                                    required
                                    maxLength={32}
                                    placeholder="Symbol, e.g. AAPL"
                                    className="hub-input min-w-40 flex-1"
                                    value={form.data.symbol}
                                    onChange={(e) =>
                                        form.setData('symbol', e.target.value)
                                    }
                                />
                                <button
                                    className="hub-button primary"
                                    disabled={form.processing}
                                >
                                    Save symbol
                                </button>
                                <Errors errors={form.errors} />
                            </form>
                            {watchlists.length ? (
                                <ul className="divide-y divide-[var(--kit-line)] rounded-xl border border-[var(--kit-line)]">
                                    {watchlists.map((item) => (
                                        <li
                                            className="flex items-center justify-between px-4 py-2.5"
                                            key={item.id}
                                        >
                                            <span className="flex items-center gap-3 font-mono text-sm">
                                                <span className="grid size-8 place-items-center rounded-lg bg-[var(--kit-hover)] text-[11px] font-semibold">
                                                    {item.symbol.slice(0, 2)}
                                                </span>
                                                {item.symbol}
                                            </span>
                                            <button
                                                className="hub-button"
                                                onClick={() =>
                                                    router.delete(
                                                        `/portfolio/watchlists/${item.id}`,
                                                    )
                                                }
                                                aria-label={`Remove ${item.symbol} from watchlist`}
                                            >
                                                Remove
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <Empty title="Your watchlist is empty">
                                    Save symbols you want to research. Price
                                    feeds remain unavailable until connected.
                                </Empty>
                            )}
                        </>
                    ) : tab === 'risk' ? (
                        snapshot?.risk ? (
                            <DataTable
                                rows={[snapshot.risk]}
                                empty="No verified risk settings"
                            />
                        ) : (
                            <Empty title="Risk data is unavailable">
                                No validated risk settings or analytics were
                                supplied by the native account.
                            </Empty>
                        )
                    ) : tab === 'history' ? (
                        snapshot?.history?.length ? (
                            <DataTable
                                rows={snapshot.history}
                                empty="No verified performance history"
                            />
                        ) : (
                            <Empty title="Performance history is unavailable">
                                A current account snapshot cannot establish
                                historical returns. No synthetic performance is
                                shown.
                            </Empty>
                        )
                    ) : (
                        <DataTable
                            rows={snapshot?.[tab] ?? []}
                            empty={`No verified ${tab}`}
                        />
                    )}
                </Panel>
                <div className="grid gap-4 md:grid-cols-2">
                    <Panel title="Connected capabilities">
                        {snapshot?.capabilities ? (
                            <>
                                <div className="mb-4 flex flex-wrap gap-2">
                                    {snapshot.capabilities.available.map(
                                        (item) => (
                                            <span
                                                key={item}
                                                className="kit-pill capitalize"
                                            >
                                                <StatusMark
                                                    status="available"
                                                    size={11}
                                                />
                                                {item.replaceAll('_', ' ')}
                                            </span>
                                        ),
                                    )}
                                </div>
                                <p className="text-xs leading-6 text-muted-foreground">
                                    Unavailable:{' '}
                                    {snapshot.capabilities.unavailable
                                        .map((item) =>
                                            item.replaceAll('_', ' '),
                                        )
                                        .join(', ') || 'None reported'}
                                </p>
                            </>
                        ) : (
                            <Quiet>
                                The source has not reported its supported
                                capabilities.
                            </Quiet>
                        )}
                    </Panel>
                    <Panel title="Portfolio tools">
                        <p className="mb-4 text-sm leading-7 text-muted-foreground">
                            Export the current verified account snapshot for
                            independent review.
                        </p>
                        <div className="flex gap-3">
                            {(['json', 'csv'] as const).map((format) => (
                                <Button
                                    key={format}
                                    type="button"
                                    variant="secondary"
                                    disabled={!snapshot}
                                    title={
                                        snapshot
                                            ? `Export native ${format.toUpperCase()}`
                                            : 'A verified native snapshot is required to export'
                                    }
                                    onClick={() => {
                                        if (snapshot)
                                            window.location.assign(
                                                `/portfolio/export?format=${format}`,
                                            );
                                    }}
                                >
                                    Export {format.toUpperCase()}
                                </Button>
                            ))}
                        </div>
                    </Panel>
                </div>
            </section>
        </>
    );
}
