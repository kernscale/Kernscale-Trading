// Capitalio registry PerformancePanel, AllocationPanel, HoldingsTable and SmartInsightsPanel structures.
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dashboard3dScaleIcon,
    DashboardTaxesIcon,
    DashboardReloadHorizontalIcon,
} from '@/components/watermelon/capitalio-dashboard/components/capitalio/icons';
import {
    ChartMarker,
    DashboardCard,
    DetailHeader,
    DetailTag,
} from '@/components/capitalio/shared';
import {
    NativeDonut,
    NativeLegend,
    NativeLine,
} from '@/components/capitalio/native-charts';
import { usd, type NativePortfolio } from '@/components/capitalio/native-data';
import { Quiet, StatusMark } from '@/components/kit/micro';
export function PerformancePanel({
    data,
    analytics = false,
}: {
    data: NativePortfolio;
    analytics?: boolean;
}) {
    return (
        <DashboardCard
            className={`flex flex-col gap-6 ${analytics ? '' : 'xl:col-span-2'}`}
        >
            <DetailHeader
                title="Portfolio Performance"
                subtitle={
                    analytics
                        ? 'Observed paper equity; no reconstructed or return-adjusted series'
                        : undefined
                }
            >
                <DetailTag color="var(--chart-4)">
                    {data.history.length ? 'Observed equity' : 'Unavailable'}
                </DetailTag>
                <Button asChild type="button" variant="secondary" size="sm">
                    <Link href="/portfolio?view=analytics">
                        <span className="truncate">Full Analysis</span>
                        <ArrowRight className="size-4 shrink-0" />
                    </Link>
                </Button>
            </DetailHeader>
            <NativeLine data={data} />
            <div className="flex flex-wrap items-center justify-end gap-4">
                {[
                    { label: 'Observed equity', color: 'var(--chart-4)' },
                    { label: 'S&P 500 unavailable', color: 'var(--chart-1)' },
                    ...(analytics
                        ? [
                              {
                                  label: '60/40 unavailable',
                                  color: 'var(--chart-2)',
                              },
                          ]
                        : []),
                ].map((item) => (
                    <div
                        key={item.label}
                        className="flex items-center gap-2 text-sm text-foreground/80"
                    >
                        <ChartMarker color={item.color} />
                        <span>{item.label}</span>
                    </div>
                ))}
            </div>
            <p className="text-xs leading-5 text-[var(--kit-faint)]">
                {data.snapshot?.history_provenance ??
                    'No verified observed-equity history is available.'}{' '}
                Funding changes affect equity; this is not investment return.
                Benchmarks are unavailable.
            </p>
            {!analytics && (
                <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                    {[
                        { label: 'Stocks', color: 'var(--chart-1)' },
                        { label: 'Real estate', color: 'var(--chart-2)' },
                        { label: 'Crypto', color: 'var(--chart-3)' },
                        { label: 'Fixed Income', color: 'var(--chart-4)' },
                    ].map((item) => (
                        <div
                            key={item.label}
                            className="rounded-xl border border-[var(--kit-line)] bg-[var(--kit-surface)] px-3.5 py-3"
                        >
                            <div className="flex items-center gap-2 text-[13px] text-muted-foreground">
                                <ChartMarker
                                    color={item.color}
                                    className="size-2.5"
                                />
                                {item.label}
                            </div>
                            <div className="mt-2.5 text-xl leading-none font-medium text-[var(--kit-faint)]">
                                —
                            </div>
                            <div className="mt-1.5 text-[11.5px] text-[var(--kit-faint)]">
                                No class-return series
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </DashboardCard>
    );
}
export function AllocationPanel({ data }: { data: NativePortfolio }) {
    return (
        <DashboardCard className="flex flex-col gap-6">
            <DetailHeader title="Asset Allocation">
                <DetailTag>
                    {data.holdings.length ? 'By holding' : 'Unavailable'}
                </DetailTag>
            </DetailHeader>
            <div className="flex flex-1 flex-col justify-between gap-6">
                <NativeDonut data={data} />
                <NativeLegend data={data} />
                <p className="text-xs leading-5 text-muted-foreground">
                    {data.snapshot
                        ? `Native USD holdings · ${data.snapshot.observed_at}`
                        : 'No verified holdings snapshot'}
                    . Cash excluded. Native asset-class taxonomy is unavailable.
                </p>
            </div>
        </DashboardCard>
    );
}
export function HoldingsTable({ data }: { data: NativePortfolio }) {
    const rows = (data.snapshot?.holdings ?? []).map((holding, index) => ({
        no: index + 1,
        asset:
            typeof holding.symbol === 'string'
                ? holding.symbol
                : 'Unknown symbol',
        type:
            typeof holding.asset_class === 'string'
                ? holding.asset_class
                : 'Classification unavailable',
        value: usd(holding.market_value),
        allocation: data.holdings.find((row) => row.symbol === holding.symbol)
            ?.percent,
        quote:
            typeof holding.quote_asof === 'string'
                ? holding.quote_asof
                : 'Quote time unavailable',
        stale: holding.stale === true,
    }));
    const asset = (row: (typeof rows)[number]) => (
        <div className="flex min-w-0 items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-xs font-semibold">
                {row.asset.slice(0, 2)}
            </span>
            <div className="min-w-0">
                <div className="truncate text-sm leading-none font-medium">
                    {row.asset}
                </div>
                <div className="mt-1 text-xs leading-none text-muted-foreground">
                    {row.type}
                    {row.stale ? ' · stale marks' : ''}
                </div>
            </div>
        </div>
    );
    return (
        <DashboardCard className="flex flex-col gap-4 lg:col-span-2">
            <DetailHeader title="Top Holdings">
                <Button asChild type="button" variant="secondary" size="sm">
                    <Link href="/portfolio?view=holdings">
                        <span className="truncate">View all</span>
                        <ArrowRight className="size-4 shrink-0" />
                    </Link>
                </Button>
            </DetailHeader>
            {rows.length ? (
                <>
                    <div className="hidden min-w-0 md:block">
                        <div className="grid grid-cols-12 items-center rounded-lg border border-border bg-foreground/5 px-4 py-3 text-sm font-medium text-foreground/80">
                            {[
                                'No',
                                'Asset',
                                'Value',
                                'Allocation',
                                'Return',
                                '24H',
                            ].map((title, index) => (
                                <div
                                    key={title}
                                    className={
                                        index === 0 || index === 5
                                            ? 'col-span-1'
                                            : index === 1
                                              ? 'col-span-4'
                                              : 'col-span-2'
                                    }
                                >
                                    {title}
                                </div>
                            ))}
                        </div>
                        {rows.map((row) => (
                            <div
                                key={row.no}
                                className="grid grid-cols-12 items-center border-b border-border px-4 py-3 last:border-b-0"
                            >
                                <div className="col-span-1 text-sm text-muted-foreground">
                                    {row.no}
                                </div>
                                <div className="col-span-4 min-w-0">
                                    {asset(row)}
                                </div>
                                <div className="col-span-2 text-sm">
                                    {row.value}
                                </div>
                                <div className="col-span-2 text-sm">
                                    {row.allocation === undefined
                                        ? 'Unavailable'
                                        : `${row.allocation.toFixed(1)}%`}
                                </div>
                                <div className="col-span-2 text-xs text-muted-foreground">
                                    Unavailable
                                </div>
                                <div className="col-span-1 text-xs text-muted-foreground">
                                    —
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="grid gap-3 md:hidden">
                        {rows.map((row) => (
                            <div
                                key={row.no}
                                className="rounded-lg border border-border p-3"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    {asset(row)}
                                    <span className="text-sm text-muted-foreground">
                                        {row.no}
                                    </span>
                                </div>
                                <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Value
                                        </div>
                                        <div>{row.value}</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Allocation
                                        </div>
                                        <div>
                                            {row.allocation === undefined
                                                ? 'Unavailable'
                                                : `${row.allocation.toFixed(1)}%`}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            Return
                                        </div>
                                        <div>Unavailable</div>
                                    </div>
                                    <div>
                                        <div className="text-xs text-muted-foreground">
                                            24H
                                        </div>
                                        <div>Unavailable</div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {rows.some((row) => row.stale)
                            ? 'Saved market marks are stale. '
                            : ''}
                        Selected-period return and 24H movement are unavailable;
                        cost basis is not substituted.
                    </p>
                </>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="hidden grid-cols-12 items-center rounded-lg border border-border bg-foreground/5 px-4 py-3 text-sm font-medium text-foreground/50 md:grid">
                        <div className="col-span-1">No</div>
                        <div className="col-span-5">Asset</div>
                        <div className="col-span-2">Value</div>
                        <div className="col-span-2">Allocation</div>
                        <div className="col-span-2">Return</div>
                    </div>
                    <Quiet className="justify-center py-8 text-center">
                        No verified holdings snapshot yet. Sample holdings,
                        logos, returns and daily changes are never substituted.
                    </Quiet>
                </div>
            )}
        </DashboardCard>
    );
}
const insights = [
    {
        title: 'Rebalance opportunity',
        description: 'Needs a validated target allocation.',
        action: 'Rebalance Now',
        icon: Dashboard3dScaleIcon,
    },
    {
        title: 'Tax-loss harvesting',
        description: 'Needs a qualified native tax-lot dataset.',
        action: 'Review',
        icon: DashboardTaxesIcon,
    },
    {
        title: 'Dividend reinvestment',
        description: 'Needs a verified dividend or DRIP feed.',
        action: 'Enable DRIP',
        icon: DashboardReloadHorizontalIcon,
    },
];
export function SmartInsightsPanel() {
    return (
        <DashboardCard className="flex flex-col gap-6">
            <DetailHeader title="Smart Insights">
                <DetailTag>Unavailable</DetailTag>
            </DetailHeader>
            <ul className="flex flex-col gap-1">
                {insights.map((item) => (
                    <li
                        key={item.title}
                        className="flex items-center gap-3 rounded-xl px-2 py-3 transition-colors hover:bg-[var(--kit-surface)]"
                    >
                        <div className="dashboard-icon-badge flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg p-1.5 text-white">
                            <item.icon className="size-5 text-white" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-[14px] leading-tight font-medium">
                                {item.title}
                            </div>
                            <div className="mt-1 text-[12.5px] leading-snug text-muted-foreground">
                                {item.description}
                            </div>
                        </div>
                        <span
                            className="kit-pill shrink-0"
                            title={`${item.action} becomes available once the data is connected`}
                        >
                            <StatusMark status="idle" size={11} />
                            Needs data
                        </span>
                    </li>
                ))}
            </ul>
        </DashboardCard>
    );
}
