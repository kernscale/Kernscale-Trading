import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import {
    DashboardCard,
    DetailHeader,
    DetailTag,
} from '@/components/capitalio/shared';
import { Button } from '@/components/ui/button';
import { displayValue } from '@/components/hub/shared';
import type { AgentStatus, Output, RecordData } from '@/types/hub';
export default function ResearchOperations({
    agentStatus,
    latestDecision,
    counts,
    recentOutputs,
}: {
    agentStatus: AgentStatus | null;
    latestDecision: RecordData | null;
    counts: { sessions: number; outputs: number; pendingReview: number };
    recentOutputs: Output[];
}) {
    return (
        <section className="flex flex-col gap-4 px-4 pb-8 md:px-8">
            <div className="grid min-w-0 gap-3 lg:grid-cols-3">
                {[
                    { title: 'Research sessions', value: counts.sessions },
                    { title: 'Agent outputs', value: counts.outputs },
                    {
                        title: 'Awaiting owner review',
                        value: counts.pendingReview,
                    },
                ].map((item) => (
                    <DashboardCard key={item.title} className="space-y-4">
                        <h2 className="text-base leading-6 font-normal">
                            {item.title}
                        </h2>
                        <p className="text-3xl leading-none font-bold">
                            {item.value}
                        </p>
                    </DashboardCard>
                ))}
            </div>
            <div className="grid min-w-0 gap-3 xl:grid-cols-2">
                <DashboardCard className="flex flex-col gap-6">
                    <DetailHeader
                        title="Scheduled research"
                        subtitle={
                            agentStatus?.observed_at ??
                            'No verified scheduler observation'
                        }
                    />
                    <dl className="space-y-3 text-sm">
                        {[
                            ['Latest outcome', agentStatus?.latest_run?.status],
                            ['Schedule state', agentStatus?.schedule?.state],
                            [
                                'Configured cadence',
                                agentStatus?.schedule?.on_calendar,
                            ],
                            ['Next run', agentStatus?.schedule?.next_run],
                            [
                                'Worker observation',
                                agentStatus?.worker_active === true
                                    ? 'Active'
                                    : agentStatus?.worker_active === false
                                      ? 'Inactive'
                                      : null,
                            ],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="flex justify-between gap-4"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="text-right">
                                    {value ?? 'Unavailable'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <div className="border-t border-border pt-4">
                        <h3 className="mb-3 font-medium">
                            Latest scheduled decision
                        </h3>
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
                                <p className="mb-3 text-sm text-muted-foreground">
                                    {displayValue(
                                        latestDecision.reason,
                                        'No decision explanation was supplied.',
                                    )}
                                </p>
                                <DetailTag>
                                    {displayValue(
                                        latestDecision.card_status,
                                        'Decision status unavailable',
                                    )}
                                </DetailTag>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No verified decision receipt is available.
                            </p>
                        )}
                    </div>
                    <Button
                        asChild
                        variant="secondary"
                        size="sm"
                        className="self-end"
                    >
                        <Link href="/outputs">
                            Review receipts
                            <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                </DashboardCard>
                <DashboardCard className="flex flex-col gap-6">
                    <DetailHeader title="Recent outputs">
                        <DetailTag>{recentOutputs.length} available</DetailTag>
                    </DetailHeader>
                    {recentOutputs.length ? (
                        <div className="divide-y divide-border">
                            {recentOutputs.map((output) => (
                                <div
                                    key={output.id}
                                    className="flex items-center justify-between gap-4 py-4"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {output.title}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {output.kind} ·{' '}
                                            {output.author?.name ??
                                                'Research output'}
                                        </p>
                                    </div>
                                    <DetailTag>{output.status}</DetailTag>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            No research outputs are available yet.
                        </p>
                    )}
                    <Button
                        asChild
                        variant="secondary"
                        size="sm"
                        className="self-end"
                    >
                        <Link href="/outputs">
                            View outputs
                            <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                </DashboardCard>
            </div>
        </section>
    );
}
