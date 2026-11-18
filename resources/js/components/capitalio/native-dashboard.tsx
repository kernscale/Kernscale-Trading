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
import { StatusMark } from '@/components/kit/micro';
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
            <div className="flex flex-wrap items-center gap-2 text-xs text-[var(--kit-faint)]">
                <span className="kit-pill">
                    <StatusMark
                        status={data.snapshot ? 'available' : 'idle'}
                        size={11}
                    />
                    {data.snapshot?.source ?? 'No source connected'}
                </span>
                <span className="kit-pill">
                    Observed {data.snapshot?.observed_at ?? '—'}
                </span>
                <span className="kit-pill">
                    {period.label} · up to {period.days} days
                </span>
                <span>History is not backfilled.</span>
            </div>
        </section>
    );
}
