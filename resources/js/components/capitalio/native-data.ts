import {
    decimalValue,
    holdingsAllocation,
    observedEquity,
    usd,
} from '@/components/hub/portfolio-data';
import type { PortfolioSnapshot } from '@/types/hub';
export const periods = [
    { value: 'monthly', label: 'Monthly', days: 30 },
    { value: 'weekly', label: 'Weekly', days: 7 },
    { value: 'quarterly', label: 'Quarterly', days: 90 },
    { value: 'yearly', label: 'Yearly', days: 365 },
] as const;
export function selectedPeriod(url: string) {
    return (
        periods.find(
            (period) =>
                period.value ===
                new URLSearchParams(url.split('?')[1]).get('period'),
        ) ?? periods[0]
    );
}
export function nativePortfolio(
    snapshot: PortfolioSnapshot | null,
    days: number,
) {
    snapshot = snapshot?.source === 'native-paper-ledger' ? snapshot : null;
    const completeHistory = observedEquity(snapshot?.history);
    const cutoff = completeHistory
        ? completeHistory.at(-1)!.timestamp - days * 86400000
        : 0;
    const history =
        completeHistory?.filter((point) => point.timestamp >= cutoff) ?? [];
    const holdings = holdingsAllocation(snapshot?.holdings, snapshot?.source);
    const total =
        holdings?.reduce((sum, holding) => sum + holding.value, 0) ?? 0;
    return {
        snapshot,
        equity: decimalValue(snapshot?.metrics?.equity),
        history: history.length >= 2 ? history : [],
        holdings:
            holdings?.map((holding, index) => ({
                ...holding,
                percent: (holding.value / total) * 100,
                color: [
                    'var(--chart-1)',
                    'var(--chart-4)',
                    'var(--chart-2)',
                    'var(--chart-3)',
                ][index % 4],
            })) ?? [],
        total,
    };
}
export type NativePortfolio = ReturnType<typeof nativePortfolio>;
export function compactUsd(value: unknown) {
    const number = decimalValue(value);
    return number === null
        ? 'Unavailable'
        : new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
              notation: 'compact',
              maximumFractionDigits: 2,
          }).format(number);
}
export { decimalValue, usd };
