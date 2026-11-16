// Capitalio registry DashboardPage: identical section/summary/detail/bottom composition.
import { usePage } from '@inertiajs/react';
import CapitalioSummary from '@/components/capitalio/summary';
import {
    AllocationPanel,
    HoldingsTable,
    PerformancePanel,
    SmartInsightsPanel,
} from '@/components/capitalio/native-panels';
import {
    nativePortfolio,
    selectedPeriod,
} from '@/components/capitalio/native-data';
import type { PortfolioSnapshot } from '@/types/hub';
export default function NativeDashboard({
    snapshot,
}: {
    snapshot: PortfolioSnapshot | null;
}) {
    const period = selectedPeriod(usePage().url);
    const data = nativePortfolio(snapshot, period.days);
    return (
        <section className="flex min-h-full flex-col gap-4 p-4 pb-8 md:p-8">
            <CapitalioSummary data={data} />
            <div className="grid min-w-0 gap-3 xl:grid-cols-3">
                <PerformancePanel data={data} />
                <AllocationPanel data={data} />
            </div>
            <div className="grid min-w-0 gap-3 lg:grid-cols-3">
                <HoldingsTable data={data} />
                <SmartInsightsPanel />
            </div>
            <p className="text-xs leading-5 text-muted-foreground">
                Source: {data.snapshot?.source ?? 'Unavailable'} · Observed:{' '}
                {data.snapshot?.observed_at ?? 'Unavailable'} · {period.label}{' '}
                selects up to {period.days} days of available observations.
                History is not backfilled.
            </p>
        </section>
    );
}
