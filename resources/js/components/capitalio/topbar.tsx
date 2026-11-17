// Capitalio registry Topbar; only actual title, observation dates and supported exports replace samples.
import { router, usePage } from '@inertiajs/react';
import {
    TopbarCalendarIcon,
    TopbarChevronIcon,
    TopbarExportIcon,
} from '@/components/watermelon/capitalio-dashboard/components/capitalio/icons';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    nativePortfolio,
    periods,
    selectedPeriod,
} from '@/components/capitalio/native-data';
import type { PortfolioSnapshot, AgentStatus } from '@/types/hub';
export default function CapitalioTopbar() {
    const { url, props } = usePage();
    const receivedSnapshot = (props.snapshot ?? props.portfolio) as
        | PortfolioSnapshot
        | null
        | undefined;
    const snapshot =
        receivedSnapshot?.source === 'native-paper-ledger'
            ? receivedSnapshot
            : null;
    const agent = props.agentStatus as AgentStatus | null | undefined;
    const analytics =
        url.startsWith('/portfolio') && url.includes('view=analytics');
    const financial =
        url.startsWith('/dashboard') || url.startsWith('/portfolio');
    const title = analytics
        ? 'Analytics'
        : url.startsWith('/dashboard')
          ? 'Overview'
          : url.startsWith('/research')
            ? 'Research'
            : url.startsWith('/outputs')
              ? 'Agent outputs'
              : url.startsWith('/portfolio')
                ? 'Portfolio'
                : url.startsWith('/community')
                  ? 'Community'
                  : url.startsWith('/admin')
                    ? 'Administration'
                    : 'Account settings';
    const observed = snapshot?.observed_at ?? agent?.observed_at;
    const period = selectedPeriod(url);
    const changePeriod = (value: string) => {
        const query = new URLSearchParams(url.split('?')[1]);
        query.set('period', value);
        router.get(url.split('?')[0], Object.fromEntries(query), {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const history = nativePortfolio(snapshot, period.days).history;
    const first = history[0]?.observed_at;
    const last = history.at(-1)?.observed_at;
    const range =
        first && last
            ? `${new Date(first).toLocaleDateString()} - ${new Date(last).toLocaleDateString()}`
            : 'Awaiting account data';
    return (
        <header className="flex shrink-0 flex-col gap-3 px-4 py-3 md:flex-row md:items-center md:justify-between md:px-8">
            <div className="flex min-w-0 items-center gap-3">
                <SidebarTrigger className="size-8.5 shrink-0 md:hidden [&_svg]:size-5!" />
                <div className="flex min-w-0 flex-1 items-center justify-between gap-3 md:flex-col md:items-start md:justify-center md:gap-1.5">
                    <h1 className="font-section truncate text-lg leading-none font-semibold text-foreground md:text-xl">
                        {title}
                    </h1>
                    <div className="font-section flex shrink-0 items-center gap-1.5 text-xs text-foreground md:gap-2 md:text-sm">
                        {financial && snapshot ? (
                            <>
                                <span className="whitespace-nowrap">
                                    <span className="hidden text-muted-foreground md:inline">
                                        Last observation{' '}
                                    </span>
                                    {observed
                                        ? new Date(observed).toLocaleString()
                                        : 'Unavailable'}
                                </span>
                                <span className="size-2 shrink-0 rounded-full bg-muted-foreground/50" />
                                <span className="shrink-0 font-medium">
                                    <span className="hidden font-normal text-muted-foreground md:inline">
                                        {snapshot
                                            ? 'All values in '
                                            : 'Currency '}
                                    </span>
                                    {snapshot ? 'USD' : 'Unavailable'}
                                </span>
                            </>
                        ) : financial ? (
                            <span className="text-muted-foreground">
                                Awaiting verified paper-account data
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                {url.startsWith('/research')
                                    ? 'Private research workspace'
                                    : 'Approved member workspace'}
                            </span>
                        )}
                    </div>
                </div>
            </div>
            {financial && (
                <div className="grid min-w-0 gap-2 sm:flex sm:items-center md:shrink-0">
                    <div className="flex min-w-0 items-center">
                        <div className="flex h-9 min-w-0 flex-1 items-center gap-2 rounded-l-md border border-r-0 border-border bg-background px-2.5 text-sm text-muted-foreground shadow-xs md:px-3 dark:border-input dark:bg-input/30">
                            <TopbarCalendarIcon className="size-4 shrink-0" />
                            <span className="truncate">{range}</span>
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-9 shrink-0 gap-2 rounded-l-none bg-card px-2.5 font-normal text-muted-foreground hover:bg-card hover:text-foreground md:px-3"
                                >
                                    <span>{period.label}</span>
                                    <TopbarChevronIcon className="h-auto w-3 rotate-180" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {periods.map((option) => (
                                    <DropdownMenuItem
                                        key={option.value}
                                        onClick={() =>
                                            changePeriod(option.value)
                                        }
                                    >
                                        {option.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <Button
                        type="button"
                        className="sm:shrink-0"
                        disabled={!snapshot}
                        title={
                            snapshot
                                ? 'Export verified native snapshot'
                                : 'A verified native snapshot is required to export'
                        }
                        onClick={() => {
                            if (snapshot)
                                window.location.assign(
                                    '/portfolio/export?format=json',
                                );
                        }}
                    >
                        <span>Export</span>
                        <TopbarExportIcon className="size-3 rotate-180" />
                    </Button>
                </div>
            )}
        </header>
    );
}
