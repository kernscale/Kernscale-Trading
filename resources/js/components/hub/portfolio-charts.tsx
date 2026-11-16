// Capitalio/Watermelon AreaChart and tooltip language, adapted to observed native ledger data only.
import { useId } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Empty, Panel } from '@/components/hub/shared';
import {
    holdingsAllocation,
    observedEquity,
    usd,
} from '@/components/hub/portfolio-data';
import type { PortfolioSnapshot } from '@/types/hub';
const colors = [
    '#67d5ac',
    '#6c9eed',
    '#93cbd0',
    '#a9d4ac',
    '#719b8c',
    '#9caad3',
];
export default function PortfolioCharts({
    snapshot,
}: {
    snapshot: PortfolioSnapshot | null;
}) {
    const gradientId = useId().replaceAll(':', '');
    const history = observedEquity(snapshot?.history);
    const allocation = holdingsAllocation(snapshot?.holdings, snapshot?.source);
    const total =
        allocation?.reduce((sum, holding) => sum + holding.value, 0) ?? 0;
    return (
        <div className="grid min-w-0 gap-5 xl:grid-cols-[1.7fr_1fr]">
            <Panel title="Observed equity" note="USD · native paper ledger">
                {history ? (
                    <>
                        <div
                            className="h-64 min-w-0"
                            role="img"
                            aria-label={`Observed equity: ${usd(history[0].equity)} at ${history[0].observed_at} to ${usd(history.at(-1)?.equity)} at ${history.at(-1)?.observed_at}`}
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart
                                    data={history}
                                    margin={{
                                        top: 12,
                                        right: 12,
                                        bottom: 4,
                                        left: 8,
                                    }}
                                >
                                    <defs>
                                        <linearGradient
                                            id={gradientId}
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="0%"
                                                stopColor="#67d5ac"
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="100%"
                                                stopColor="#67d5ac"
                                                stopOpacity={0}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid
                                        vertical={false}
                                        stroke="#303034"
                                        strokeDasharray="3 5"
                                    />
                                    <XAxis
                                        dataKey="timestamp"
                                        type="number"
                                        domain={['dataMin', 'dataMax']}
                                        tickFormatter={(value) =>
                                            new Date(
                                                Number(value),
                                            ).toLocaleDateString(undefined, {
                                                month: 'short',
                                                day: 'numeric',
                                            })
                                        }
                                        tick={{ fill: '#939397', fontSize: 10 }}
                                        axisLine={false}
                                        tickLine={false}
                                        minTickGap={35}
                                    />
                                    <YAxis
                                        width={70}
                                        domain={['auto', 'auto']}
                                        tickFormatter={usd}
                                        tick={{ fill: '#939397', fontSize: 10 }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <Tooltip
                                        content={({ active, payload }) => {
                                            const point = payload?.[0]
                                                ?.payload as
                                                | {
                                                      equity?: number;
                                                      observed_at?: string;
                                                      market_asof?:
                                                          | string
                                                          | null;
                                                  }
                                                | undefined;
                                            return active && point ? (
                                                <div className="rounded-lg border border-border bg-popover p-3 text-xs text-popover-foreground">
                                                    <p className="mb-2 font-medium">
                                                        {usd(point.equity)}
                                                    </p>
                                                    <p>
                                                        Observed:{' '}
                                                        {point.observed_at}
                                                    </p>
                                                    <p>
                                                        Market as of:{' '}
                                                        {point.market_asof ??
                                                            'Unavailable'}
                                                    </p>
                                                    <p className="mt-2 text-muted-foreground">
                                                        native-paper-ledger
                                                    </p>
                                                </div>
                                            ) : null;
                                        }}
                                    />
                                    <Area
                                        type="linear"
                                        dataKey="equity"
                                        stroke="#67d5ac"
                                        strokeWidth={2}
                                        fill={`url(#${gradientId})`}
                                        isAnimationActive={false}
                                        dot={false}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                        <p className="mt-4 text-[11px] leading-6 break-words text-muted-foreground">
                            Observed {history[0].observed_at} to{' '}
                            {history.at(-1)?.observed_at}.{' '}
                            {snapshot?.history_provenance ??
                                'Source: native-paper-ledger. Saved account observations since capture began; no historical replay or benchmark.'}
                        </p>
                    </>
                ) : (
                    <Empty title="Observed equity history unavailable">
                        A chart requires at least two valid, distinct native
                        account observations. Missing or malformed values are
                        never replaced with a simulated series.
                    </Empty>
                )}
            </Panel>
            <Panel
                title="Holdings allocation"
                note="USD market value · excludes cash"
            >
                {allocation ? (
                    <>
                        <div
                            className="h-52 min-w-0"
                            role="img"
                            aria-label={`Verified holdings allocation across ${allocation.length} symbols, total ${usd(total)}`}
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={allocation}
                                        dataKey="value"
                                        nameKey="symbol"
                                        innerRadius="62%"
                                        outerRadius="85%"
                                        stroke="#19191b"
                                        strokeWidth={3}
                                        isAnimationActive={false}
                                    >
                                        {allocation.map((holding, index) => (
                                            <Cell
                                                key={holding.symbol}
                                                fill={
                                                    colors[
                                                        index % colors.length
                                                    ]
                                                }
                                            />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        formatter={(value) => usd(value)}
                                        contentStyle={{
                                            background: '#202023',
                                            border: '1px solid #36363b',
                                            borderRadius: 8,
                                            color: '#eeeeef',
                                            fontSize: 12,
                                        }}
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <ul className="space-y-2">
                            {allocation.map((holding, index) => (
                                <li
                                    key={holding.symbol}
                                    className="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <span
                                            className="size-2 shrink-0 rounded-sm"
                                            style={{
                                                background:
                                                    colors[
                                                        index % colors.length
                                                    ],
                                            }}
                                        />
                                        <span className="truncate">
                                            {holding.symbol}
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-muted-foreground">
                                        {usd(holding.value)} ·{' '}
                                        {(
                                            (holding.value / total) *
                                            100
                                        ).toFixed(1)}
                                        %
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 text-[11px] leading-6 text-muted-foreground">
                            Source: {snapshot?.source}. Snapshot:{' '}
                            {snapshot?.observed_at}. Every holding has a finite,
                            nonnegative USD valuation.
                        </p>
                    </>
                ) : (
                    <Empty title="Holdings allocation unavailable">
                        All holdings need verified, finite, nonnegative USD
                        market values. Unknown values, shorts, mixed currencies
                        and an empty valued portfolio cannot establish this
                        allocation.
                    </Empty>
                )}
            </Panel>
        </div>
    );
}
