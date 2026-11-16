import { Head, Link } from '@inertiajs/react';
import { useEffect, type MouseEvent } from 'react';
import { toast } from 'sonner';
import DashboardLayout from '@/components/watermelon/capitalio-dashboard/dashboard-layout';
import { DashboardPage } from '@/components/watermelon/capitalio-dashboard/components/capitalio/dashboard-page';
import { AnalyticsPage } from '@/components/watermelon/capitalio-dashboard/components/capitalio/analytics-page';
import {
    DashboardNavigationProvider,
    useDashboardNavigation,
} from '@/components/watermelon/capitalio-dashboard/components/capitalio/navigation';
import {
    analyticsDataByPeriod,
    dashboardSummaryCardsByPeriod,
    dashboardDetailByPeriod,
    dashboardBottomByPeriod,
} from '@/components/watermelon/capitalio-dashboard/data';
function Preview() {
    const { pathname, navigate, period } = useDashboardNavigation();
    const handleAction = (event: MouseEvent<HTMLDivElement>) => {
        const button = (event.target as Element).closest('button');
        if (!button) return;
        const label = button.textContent?.trim();
        if (label === 'Full Analysis') navigate('/analytics');
        if (label === 'View all') navigate('/net-worth');
        if (label === 'Export') {
            const payload = {
                sample_design_data: true,
                source: 'Capitalio registry',
                period,
                dashboard: {
                    summary: dashboardSummaryCardsByPeriod[period],
                    detail: dashboardDetailByPeriod[period],
                    bottom: dashboardBottomByPeriod[period],
                },
                analytics: analyticsDataByPeriod[period],
            };
            const url = URL.createObjectURL(
                new Blob([JSON.stringify(payload, null, 2)], {
                    type: 'application/json',
                }),
            );
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `capitalio-sample-${period}.json`;
            anchor.click();
            URL.revokeObjectURL(url);
        }
        if (['Rebalance Now', 'Review', 'Enable DRIP'].includes(label ?? ''))
            toast.info('Sample design action — no account changes.');
    };
    return (
        <div onClickCapture={handleAction}>
            <DashboardLayout>
                {pathname === '/analytics' ? (
                    <AnalyticsPage />
                ) : (
                    <DashboardPage />
                )}
            </DashboardLayout>
            <div className="fixed right-3 bottom-3 z-[80] flex max-w-[calc(100vw-24px)] flex-wrap items-center gap-3 rounded-md border border-blue-400/40 bg-[#122236] px-3 py-2 text-xs text-white shadow-lg">
                <strong>Sample design data</strong>
                <span>Local owner preview</span>
                <Link
                    href="/dashboard"
                    className="underline underline-offset-2"
                >
                    Return to workspace
                </Link>
            </div>
        </div>
    );
}
export default function CapitalioPreview() {
    useEffect(() => {
        document.documentElement.classList.add('dark');
    }, []);
    return (
        <>
            <Head title="Capitalio design preview" />
            <DashboardNavigationProvider>
                <Preview />
            </DashboardNavigationProvider>
        </>
    );
}
