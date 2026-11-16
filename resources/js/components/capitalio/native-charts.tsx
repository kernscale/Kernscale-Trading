// Capitalio registry LineChart/PerformanceChart/AllocationDonut, same marks, grids and geometry; validated native points replace fixtures.
import { useId } from 'react';
import { cn } from '@/lib/utils';
import {
    Area,
    AreaChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartMarker,
    DashboardChartTooltip,
} from '@/components/capitalio/shared';
import {
    compactUsd,
    usd,
    type NativePortfolio,
} from '@/components/capitalio/native-data';
export function Unavailable({
    children,
    className = '',
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-h-16 min-w-0 items-center justify-center rounded-md px-3 text-center text-xs leading-5 text-muted-foreground',
                className,
            )}
        >
            {children}
        </div>
    );
}
export function NativeLine({
    data,
    mini = false,
}: {
    data: NativePortfolio;
    mini?: boolean;
}) {
    const id = useId().replaceAll(':', '');
    const points = data.history.map((point) => ({
        label: new Date(point.timestamp).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        }),
        portfolio: point.equity,
        observed_at: point.observed_at,
        market_asof: point.market_asof,
    }));
    if (!points.length)
        return (
            <Unavailable
                className={mini ? 'h-16' : 'dashboard-dot-grid min-h-72 flex-1'}
            >
                Equity history is not available yet.
            </Unavailable>
        );
    return (
        <div
            className={
                mini ? 'space-y-2' : 'flex min-h-80 min-w-0 flex-col gap-3'
            }
        >
            <div
                role="img"
                aria-label="Observed native paper equity"
                className={
                    mini
                        ? 'h-16 w-full text-[var(--chart-4)]'
                        : 'dashboard-dot-grid h-72 min-w-0 flex-1 overflow-hidden rounded-md'
                }
            >
                <ResponsiveContainer width="100%" height="100%">
                    <AreaChart
                        data={points}
                        margin={{
                            top: mini ? 2 : 8,
                            right: mini ? 0 : 8,
                            bottom: 0,
                            left: 0,
                        }}
                    >
                        <defs>
                            <linearGradient id={id} x1="0" x2="0" y1="0" y2="1">
                                <stop
                                    offset="0%"
                                    stopColor="var(--chart-4)"
                                    stopOpacity={mini ? 0.32 : 0.42}
                                />
                                <stop
                                    offset="100%"
                                    stopColor="var(--chart-4)"
                                    stopOpacity={0}
                                />
                            </linearGradient>
                        </defs>
                        <XAxis
                            dataKey="observed_at"
                            hide={mini}
                            axisLine={false}
                            tick={false}
                            tickLine={false}
                        />
                        <YAxis
                            hide={mini}
                            axisLine={false}
                            domain={['auto', 'auto']}
                            tick={{
                                fill: 'var(--muted-foreground)',
                                fontSize: '0.75rem',
                            }}
                            tickFormatter={compactUsd}
                            tickLine={false}
                            width={65}
                        />
                        <Tooltip
                            cursor={false}
                            content={({ active, payload }) => {
                                const point = payload?.[0]?.payload as
                                    | {
                                          observed_at: string;
                                          market_asof: string | null;
                                      }
                                    | undefined;
                                return (
                                    <>
                                        <DashboardChartTooltip
                                            active={active}
                                            payload={payload?.map((item) => ({
                                                dataKey: 'portfolio',
                                                name: 'Observed equity',
                                                value:
                                                    typeof item.value ===
                                                    'number'
                                                        ? item.value
                                                        : undefined,
                                                color: 'var(--chart-4)',
                                            }))}
                                            label={point?.observed_at}
                                            colors={{
                                                portfolio: 'var(--chart-4)',
                                            }}
                                            names={{
                                                portfolio: 'Observed equity',
                                            }}
                                            valueFormatter={usd}
                                        />
                                        {active && point && (
                                            <div className="rounded-b-md bg-popover p-2 text-[10px] text-muted-foreground">
                                                Market as of:{' '}
                                                {point.market_asof ??
                                                    'Unavailable'}
                                            </div>
                                        )}
                                    </>
                                );
                            }}
                        />
                        <Area
                            dataKey="portfolio"
                            dot={false}
                            fill={`url(#${id})`}
                            isAnimationActive={false}
                            stroke="var(--chart-4)"
                            strokeWidth={mini ? 2 : 3}
                            type="monotone"
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </div>
            <div
                className={
                    mini
                        ? 'grid grid-cols-3 text-xs leading-none text-muted-foreground'
                        : 'grid min-w-0 grid-cols-3 pl-16 text-center text-xs text-muted-foreground'
                }
            >
                {[
                    points[0],
                    points[Math.floor(points.length / 2)],
                    points.at(-1)!,
                ].map((point, index) => (
                    <span
                        key={index}
                        className={
                            mini && index === 2
                                ? 'text-right'
                                : index === 1
                                  ? 'text-center'
                                  : ''
                        }
                    >
                        {point.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
export function NativeDonut({ data }: { data: NativePortfolio }) {
    if (!data.holdings.length)
        return (
            <Unavailable className="mx-auto aspect-square w-full max-w-56 rounded-full border-[20px] border-muted/40">
                Allocation is not available yet.
            </Unavailable>
        );
    return (
        <div className="relative mx-auto aspect-square w-full max-w-56">
            <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                    <Pie
                        cornerRadius={4}
                        data={data.holdings.map((holding) => ({
                            name: holding.symbol,
                            value: holding.percent,
                            fill: holding.color,
                        }))}
                        dataKey="value"
                        endAngle={-270}
                        innerRadius="68%"
                        isAnimationActive={false}
                        outerRadius="88%"
                        paddingAngle={6}
                        startAngle={90}
                        stroke="none"
                    />
                    <Tooltip
                        wrapperStyle={{ zIndex: 20 }}
                        cursor={false}
                        content={
                            <DashboardChartTooltip
                                valueFormatter={(value) =>
                                    `${Number(value).toFixed(1)}%`
                                }
                            />
                        }
                    />
                </PieChart>
            </ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 z-0 flex flex-col items-center justify-center">
                <div className="text-3xl leading-none font-semibold">
                    {data.holdings.length}
                </div>
                <div className="mt-2 text-base text-muted-foreground">
                    Symbols
                </div>
            </div>
        </div>
    );
}
export function NativeLegend({ data }: { data: NativePortfolio }) {
    return (
        <div className="space-y-5">
            {data.holdings.map((holding) => (
                <div
                    key={holding.symbol}
                    className="flex items-center justify-between gap-4"
                >
                    <div className="flex min-w-0 items-center gap-3">
                        <ChartMarker color={holding.color} className="size-4" />
                        <span className="truncate text-base font-medium text-foreground/80">
                            {holding.symbol}
                        </span>
                    </div>
                    <div className="flex shrink-0 items-center gap-4">
                        <span className="text-base font-medium text-foreground/80">
                            {compactUsd(holding.value)}
                        </span>
                        <span className="min-w-10 text-right text-base text-muted-foreground">
                            {holding.percent.toFixed(1)}%
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}
