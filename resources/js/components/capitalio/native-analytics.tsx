// Capitalio registry AnalyticsPage: all performance, three mini-metric and two lower chart blocks retained.
import { usePage } from '@inertiajs/react';
import {
    ChartMarker,
    DashboardCard,
    DetailHeader,
    DetailTag,
} from '@/components/capitalio/shared';
import { NativeLine, Unavailable } from '@/components/capitalio/native-charts';
import { PerformancePanel } from '@/components/capitalio/native-panels';
import {
    compactUsd,
    nativePortfolio,
    selectedPeriod,
} from '@/components/capitalio/native-data';
import type { PortfolioSnapshot } from '@/types/hub';
export default function NativeAnalytics({
    snapshot,
}: {
    snapshot: PortfolioSnapshot | null;
}) {
    const period = selectedPeriod(usePage().url);
    const data = nativePortfolio(snapshot, period.days);
    return (
        <section className="flex min-h-full flex-col gap-4 p-4 pb-8 md:p-8">
            <PerformancePanel data={data} analytics />
            <div className="grid min-w-0 gap-3 lg:grid-cols-3">
                {[
                    {
                        title: 'Total Net worth',
                        value: compactUsd(data.equity),
                        description:
                            'Observed paper equity; not a risk-adjusted ratio',
                        color: 'var(--chart-4)',
                    },
                    {
                        title: 'Risk score',
                        value: 'Unavailable',
                        description: 'No validated risk-score methodology',
                        color: 'var(--chart-5)',
                    },
                    {
                        title: 'Portfolio return',
                        value: 'Unavailable',
                        description: 'No verified period-return series',
                        color: 'var(--chart-3)',
                    },
                ].map((metric, index) => (
                    <DashboardCard
                        key={metric.title}
                        className="flex min-h-40 flex-col gap-5"
                    >
                        <div>
                            <h2 className="truncate text-base leading-6 font-medium">
                                {metric.title}
                            </h2>
                            <div className="mt-4 text-3xl leading-none font-semibold">
                                {metric.value}
                            </div>
                            <div className="mt-1 truncate text-xs text-muted-foreground">
                                {metric.description}
                            </div>
                        </div>
                        <div className="min-h-16 flex-1">
                            {index === 0 ? (
                                <NativeLine data={data} mini />
                            ) : (
                                <Unavailable>
                                    {metric.description}. No sample chart is
                                    shown.
                                </Unavailable>
                            )}
                        </div>
                    </DashboardCard>
                ))}
            </div>
            <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                {[
                    {
                        title: `${period.label} returns`,
                        subtitle: 'Per asset class',
                        labels: [
                            'Stocks',
                            'Real Estate',
                            'Crypto',
                            'Fixed Income',
                        ],
                        reason: 'No classified, return-adjusted native period series is available.',
                    },
                    {
                        title: 'Income streams',
                        subtitle: 'Dividends & rental income',
                        labels: ['Dividends', 'Rental Income'],
                        reason: 'No verified native dividend or rental-income series is available.',
                    },
                ].map((panel) => (
                    <DashboardCard
                        key={panel.title}
                        className="flex min-h-80 flex-col gap-5"
                    >
                        <DetailHeader
                            title={panel.title}
                            subtitle={panel.subtitle}
                        >
                            <DetailTag color="var(--chart-4)">
                                Unavailable
                            </DetailTag>
                        </DetailHeader>
                        <div className="flex min-w-0 flex-wrap items-center justify-center gap-5">
                            {panel.labels.map((label, index) => (
                                <div
                                    key={label}
                                    className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <ChartMarker
                                        color={
                                            [
                                                'var(--chart-1)',
                                                'var(--chart-4)',
                                                'var(--chart-3)',
                                                'var(--chart-2)',
                                            ][index]
                                        }
                                        className="size-2.5"
                                    />
                                    <span className="truncate">{label}</span>
                                </div>
                            ))}
                        </div>
                        <div className="min-h-56 flex-1">
                            <Unavailable className="h-full">
                                {panel.reason} Missing history is not replaced
                                by design fixtures.
                            </Unavailable>
                        </div>
                    </DashboardCard>
                ))}
            </div>
        </section>
    );
}
