import PortfolioCharts from '@/components/hub/portfolio-charts';
import { Head } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    FileText,
    FlaskConical,
    Layers,
} from 'lucide-react';
import {
    Empty,
    displayValue,
    GoLink,
    PageHeading,
    Panel,
    Status,
} from '@/components/hub/shared';
import type {
    AgentStatus,
    Output,
    PortfolioSnapshot,
    RecordData,
} from '@/types/hub';
export default function Dashboard({
    portfolio = null,
    agentStatus = null,
    counts = { sessions: 0, outputs: 0, pendingReview: 0 },
    recentOutputs = [],
    latestDecision = null,
}: {
    portfolio?: PortfolioSnapshot | null;
    agentStatus?: AgentStatus | null;
    latestDecision?: RecordData | null;
    counts?: { sessions: number; outputs: number; pendingReview: number };
    recentOutputs?: Output[];
}) {
    return (
        <>
            <Head title="Overview" />
            <PageHeading
                eyebrow="Your research workspace"
                title="Research overview"
            >
                <GoLink href="/research">Start research</GoLink>
            </PageHeading>
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    {
                        label: 'Research sessions',
                        value: counts.sessions,
                        icon: FlaskConical,
                        detail: 'Your private workspace',
                    },
                    {
                        label: 'Agent outputs',
                        value: counts.outputs,
                        icon: FileText,
                        detail: 'Evidence & research artifacts',
                    },
                    {
                        label: 'Awaiting review',
                        value: counts.pendingReview,
                        icon: Layers,
                        detail: 'Publication requires owner review',
                    },
                    {
                        label: 'Latest scheduled outcome',
                        value: agentStatus
                            ? displayValue(
                                  agentStatus.latest_run?.status,
                                  'Unavailable',
                              )
                            : 'Unavailable',
                        icon: Activity,
                        detail: agentStatus?.observed_at
                            ? displayValue(agentStatus.observed_at)
                            : 'No verified scheduler receipt',
                    },
                ].map((item) => (
                    <Panel key={item.label}>
                        <div className="mb-5 flex items-center justify-between text-xs text-muted-foreground">
                            {item.label}
                            <item.icon className="size-4" />
                        </div>
                        <p className="text-3xl font-medium tracking-tight">
                            {item.value}
                        </p>
                        <p className="mt-3 text-[11px] text-muted-foreground">
                            {item.detail}
                        </p>
                    </Panel>
                ))}
            </div>
            <div className="mb-5">
                <PortfolioCharts snapshot={portfolio} />
            </div>
            <div className="grid gap-5 xl:grid-cols-[1.7fr_1fr]">
                <Panel
                    title="Paper portfolio"
                    note={
                        portfolio?.observed_at ??
                        'Awaiting native account snapshot'
                    }
                >
                    <div className="mb-5 flex gap-2">
                        <Status>Authoritative native ledger</Status>
                        <Status>Read only</Status>
                    </div>
                    {portfolio ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Source: {portfolio.source}
                            </p>
                            <dl className="mt-5 grid grid-cols-2 gap-4">
                                {Object.entries(portfolio.metrics ?? {})
                                    .filter(([, v]) => typeof v !== 'object')
                                    .map(([key, value]) => (
                                        <div key={key}>
                                            <dt className="text-xs text-muted-foreground">
                                                {key.replaceAll('_', ' ')}
                                            </dt>
                                            <dd className="mt-2 text-xl">
                                                {displayValue(
                                                    value,
                                                    'Unavailable',
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                            <div className="mt-6">
                                <GoLink href="/portfolio">
                                    Explore portfolio
                                </GoLink>
                            </div>
                        </>
                    ) : (
                        <Empty title="Your portfolio is waiting for a connection">
                            Balances, holdings and performance appear when a
                            verified native account snapshot is available.
                        </Empty>
                    )}
                </Panel>
                <Panel title="Research with R1">
                    <div className="mb-6 flex size-12 items-center justify-center rounded-xl border border-emerald-200/15 bg-emerald-200/5 text-emerald-200">
                        <FlaskConical className="size-6" />
                    </div>
                    <h3 className="text-xl leading-7 tracking-tight">
                        A clear view.
                        <br />
                        Grounded in evidence.
                    </h3>
                    <p className="my-4 text-sm leading-6 text-muted-foreground">
                        Work through an investment thesis in a private session.
                        Keep sources, decisions and proposals together.
                    </p>
                    <GoLink href="/research">Open your workspace</GoLink>
                </Panel>
                <Panel
                    title="Scheduled research"
                    note={
                        agentStatus?.observed_at ??
                        'No verified scheduler receipt'
                    }
                    className="xl:col-span-2"
                >
                    <div className="grid gap-6 md:grid-cols-2">
                        <div>
                            <p className="mb-3 text-xs font-medium">Schedule</p>
                            <dl className="space-y-2 text-xs text-muted-foreground">
                                <div className="flex justify-between gap-4">
                                    <dt>State</dt>
                                    <dd>
                                        {agentStatus?.schedule?.state ??
                                            'Unavailable'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt>Configured cadence</dt>
                                    <dd className="text-right">
                                        {agentStatus?.schedule?.on_calendar ??
                                            'Unavailable'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt>Next run</dt>
                                    <dd>
                                        {agentStatus?.schedule?.next_run ??
                                            'Unavailable'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt>Worker observation</dt>
                                    <dd>
                                        {agentStatus?.worker_active === true
                                            ? 'Active'
                                            : agentStatus?.worker_active ===
                                                false
                                              ? 'Inactive'
                                              : 'Unavailable'}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div>
                            <p className="mb-3 text-xs font-medium">
                                Latest scheduled decision
                            </p>
                            {latestDecision ? (
                                <>
                                    <p className="mb-2 text-sm">
                                        {displayValue(
                                            latestDecision.symbol,
                                            'Symbol unavailable',
                                        )}{' '}
                                        ·{' '}
                                        {displayValue(
                                            latestDecision.rating,
                                            'Rating unavailable',
                                        )}
                                    </p>
                                    <p className="mb-3 text-xs leading-6 text-muted-foreground">
                                        {displayValue(
                                            latestDecision.reason,
                                            'No decision explanation was supplied.',
                                        )}
                                    </p>
                                    <Status>
                                        {displayValue(
                                            latestDecision.card_status,
                                            'Decision status unavailable',
                                        )}
                                    </Status>
                                </>
                            ) : (
                                <p className="text-xs leading-6 text-muted-foreground">
                                    No verified decision receipt is available.
                                </p>
                            )}
                            <div className="mt-4">
                                <GoLink href="/outputs">
                                    Review scheduled receipts
                                </GoLink>
                            </div>
                        </div>
                    </div>
                </Panel>
                <Panel
                    title="Recent outputs"
                    className="xl:col-span-2"
                    note="Research & review"
                >
                    {recentOutputs.length ? (
                        <div className="divide-y divide-white/5">
                            {recentOutputs.map((output) => (
                                <div
                                    className="flex items-center gap-4 py-4"
                                    key={output.id}
                                >
                                    <FileText className="size-4 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm">
                                            {output.title}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {output.kind} ·{' '}
                                            {output.author?.name ??
                                                'Research output'}
                                        </p>
                                    </div>
                                    <Status>{output.status}</Status>
                                    <ArrowRight className="size-4 text-muted-foreground" />
                                </div>
                            ))}
                            <GoLink href="/outputs">View all outputs</GoLink>
                        </div>
                    ) : (
                        <Empty title="No outputs yet">
                            Save a research document or strategy proposal to
                            begin your review workflow.
                        </Empty>
                    )}
                </Panel>
            </div>
        </>
    );
}
