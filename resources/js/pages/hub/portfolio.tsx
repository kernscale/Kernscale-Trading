import PortfolioCharts from '@/components/hub/portfolio-charts';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    DataTable,
    Empty,
    Errors,
    displayValue,
    PageHeading,
    Panel,
    Status,
} from '@/components/hub/shared';
import type { PortfolioSnapshot } from '@/types/hub';
type Watchlist = { id: number; symbol: string };
export default function Portfolio({
    snapshot = null,
    freshness,
    watchlists = [],
}: {
    snapshot?: PortfolioSnapshot | null;
    freshness?: { stale: boolean; observed_at: string | null };
    watchlists?: Watchlist[];
}) {
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
            <PageHeading eyebrow="Native paper account" title="Portfolio">
                <Status>
                    {snapshot
                        ? freshness?.stale
                            ? 'Snapshot is stale'
                            : 'Snapshot available'
                        : 'Not connected'}
                </Status>
            </PageHeading>
            <div className="mb-5">
                <PortfolioCharts snapshot={snapshot} />
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
                                    ([, value]) => typeof value !== 'object',
                                )
                                .map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="rounded-md border border-white/10 p-4"
                                    >
                                        <p className="text-xs text-muted-foreground">
                                            {key.replaceAll('_', ' ')}
                                        </p>
                                        <p className="mt-3 text-2xl">
                                            {displayValue(value, 'Unavailable')}
                                        </p>
                                    </div>
                                ))}
                        </div>
                    </>
                ) : (
                    <Empty title="Portfolio data is unavailable">
                        The shared native paper account is the source of truth.
                        A missing connection never becomes a zero balance or
                        synthetic performance.
                    </Empty>
                )}
                <div
                    className="mb-4 flex flex-wrap gap-2"
                    role="tablist"
                    aria-label="Portfolio data"
                >
                    {(
                        [
                            'holdings',
                            'accounts',
                            'activities',
                            'risk',
                            'watchlists',
                            'history',
                        ] as const
                    ).map((key) => (
                        <button
                            key={key}
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={`hub-button capitalize ${tab === key ? 'primary' : ''}`}
                        >
                            {key === 'history' ? 'Performance history' : key}
                        </button>
                    ))}
                </div>
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
                            <ul className="divide-y divide-white/10">
                                {watchlists.map((item) => (
                                    <li
                                        className="flex items-center justify-between py-3"
                                        key={item.id}
                                    >
                                        <span className="font-mono text-sm">
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
                                Save symbols you want to research. Price feeds
                                remain unavailable until connected.
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
            <div className="mt-5 grid gap-5 md:grid-cols-2">
                <Panel title="Connected capabilities">
                    {snapshot?.capabilities ? (
                        <>
                            <div className="mb-4 flex flex-wrap gap-2">
                                {snapshot.capabilities.available.map((item) => (
                                    <Status key={item}>
                                        {item.replaceAll('_', ' ')}
                                    </Status>
                                ))}
                            </div>
                            <p className="text-xs leading-6 text-muted-foreground">
                                Unavailable:{' '}
                                {snapshot.capabilities.unavailable
                                    .map((item) => item.replaceAll('_', ' '))
                                    .join(', ') || 'None reported'}
                            </p>
                        </>
                    ) : (
                        <p className="text-sm leading-7 text-muted-foreground">
                            The source has not reported its supported
                            capabilities.
                        </p>
                    )}
                </Panel>
                <Panel title="Portfolio tools">
                    <p className="mb-4 text-sm leading-7 text-muted-foreground">
                        Export the current verified account snapshot for
                        independent review.
                    </p>
                    <div className="flex gap-3">
                        <a
                            className="hub-button"
                            href="/portfolio/export?format=json"
                        >
                            Export JSON
                        </a>
                        <a
                            className="hub-button"
                            href="/portfolio/export?format=csv"
                        >
                            Export CSV
                        </a>
                    </div>
                </Panel>
            </div>
        </>
    );
}
