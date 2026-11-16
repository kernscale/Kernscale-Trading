export function decimalValue(value: unknown): number | null {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (
        typeof value === 'string' &&
        !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(value)
    )
        return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}
function timestamp(value: unknown): number | null {
    if (
        typeof value !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(
            value,
        )
    )
        return null;
    const day = value.slice(0, 10);
    const calendar = Date.parse(`${day}T00:00:00Z`);
    const parsed = Date.parse(value);
    return Number.isFinite(calendar) &&
        new Date(calendar).toISOString().slice(0, 10) === day &&
        Number.isFinite(parsed)
        ? parsed
        : null;
}
export function observedEquity(history: unknown) {
    if (!Array.isArray(history) || history.length < 2) return null;
    const points = new Map<
        number,
        {
            timestamp: number;
            equity: number;
            observed_at: string;
            source: string;
            market_asof: string | null;
        }
    >();
    for (const value of history) {
        if (!value || typeof value !== 'object') return null;
        const row = value as Record<string, unknown>;
        const time = timestamp(row.observed_at);
        const equity = decimalValue(row.equity);
        if (
            time === null ||
            equity === null ||
            row.source !== 'native-paper-ledger' ||
            (row.market_asof !== null && timestamp(row.market_asof) === null)
        )
            return null;
        points.set(time, {
            timestamp: time,
            equity,
            observed_at: String(row.observed_at),
            source: row.source,
            market_asof: row.market_asof as string | null,
        });
    }
    return points.size >= 2
        ? [...points.values()].sort((a, b) => a.timestamp - b.timestamp)
        : null;
}
export function holdingsAllocation(holdings: unknown, source: unknown) {
    if (
        source !== 'native-paper-ledger' ||
        !Array.isArray(holdings) ||
        !holdings.length
    )
        return null;
    const symbols = new Map<string, number>();
    let total = 0;
    for (const value of holdings) {
        if (!value || typeof value !== 'object') return null;
        const holding = value as Record<string, unknown>;
        const amount = decimalValue(holding.market_value);
        if (
            holding.currency !== 'USD' ||
            amount === null ||
            amount < 0 ||
            typeof holding.symbol !== 'string' ||
            !holding.symbol.trim()
        )
            return null;
        total += amount;
        symbols.set(
            holding.symbol,
            (symbols.get(holding.symbol) ?? 0) + amount,
        );
    }
    return total > 0 && Number.isFinite(total)
        ? [...symbols].map(([symbol, value]) => ({ symbol, value }))
        : null;
}
export function usd(value: unknown): string {
    const amount = decimalValue(value);
    return amount === null
        ? 'Unavailable'
        : new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
              maximumFractionDigits: 2,
          }).format(amount);
}
