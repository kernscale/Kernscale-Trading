// Capitalio registry SummaryCard/ReturnChart/AllocationChart structure; absent native metrics stay unavailable.
import {
    DashboardAnalyticsUpIcon,
    DashboardMoneyBagIcon,
    DashboardPieChartIcon,
    DashboardShieldIcon,
} from '@/components/watermelon/capitalio-dashboard/components/capitalio/icons';
import { DashboardCard } from '@/components/capitalio/shared';
import { NativeLine, Unavailable } from '@/components/capitalio/native-charts';
import {
    compactUsd,
    type NativePortfolio,
} from '@/components/capitalio/native-data';
const cards = [
    { id: 'net-worth', title: 'Total Net worth', icon: DashboardMoneyBagIcon },
    {
        id: 'portfolio-return',
        title: 'Portfolio return',
        icon: DashboardAnalyticsUpIcon,
    },
    {
        id: 'asset-classes',
        title: 'Asset classes',
        icon: DashboardPieChartIcon,
    },
    { id: 'risk-score', title: 'Risk score', icon: DashboardShieldIcon },
];
export default function CapitalioSummary({ data }: { data: NativePortfolio }) {
    return (
        <div className="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-4">
            {cards.map((card) => (
                <DashboardCard
                    key={card.id}
                    className="flex min-h-48 flex-col gap-6"
                >
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="truncate text-base leading-6 font-normal">
                            {card.title}
                        </h2>
                        <div className="dashboard-icon-badge flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg p-2 text-white shadow-none">
                            <card.icon className="size-5 shrink-0" />
                        </div>
                    </div>
                    <div className="flex items-end gap-2">
                        <div className="text-3xl leading-none font-bold tracking-normal">
                            {card.id === 'net-worth' && data.equity !== null
                                ? compactUsd(data.equity)
                                : '—'}
                        </div>
                    </div>
                    {card.id === 'net-worth' ? (
                        <>
                            <NativeLine data={data} mini />
                            <p className="text-xs text-muted-foreground">
                                Shared paper account equity
                            </p>
                        </>
                    ) : card.id === 'portfolio-return' ? (
                        <div className="space-y-3">
                            <div className="space-y-2">
                                <div className="h-2 overflow-hidden rounded-sm bg-foreground/7" />
                                <div className="flex items-center justify-between text-xs leading-none text-muted-foreground">
                                    <span>Target unavailable</span>
                                    <span>Goal unavailable</span>
                                </div>
                            </div>
                            <Unavailable className="h-8">
                                Return history is not available yet.
                            </Unavailable>
                        </div>
                    ) : card.id === 'asset-classes' ? (
                        <div className="flex flex-1 flex-col justify-between gap-6">
                            <div className="h-5 w-full rounded-md border border-border bg-muted/30" />
                            <Unavailable>
                                Asset-class breakdown is not available yet.
                            </Unavailable>
                        </div>
                    ) : (
                        <Unavailable className="h-12">
                            No risk assessment is available.
                        </Unavailable>
                    )}
                </DashboardCard>
            ))}
        </div>
    );
}
