import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { DashboardCard, DetailHeader } from '@/components/capitalio/shared';
import { displayValue } from '@/components/hub/shared';
import { CollabAvatar } from '@/components/kit/collab';
import { StatusMark } from '@/components/kit/micro';
import type { AgentStatus, Output, RecordData } from '@/types/hub';

const when = (value?: string | null) => {
    const time = value ? Date.parse(value) : Number.NaN;
    return Number.isNaN(time)
        ? null
        : new Date(time).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

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
    const run = agentStatus?.latest_run;
    const worker =
        agentStatus?.worker_active === true
            ? 'active'
            : agentStatus?.worker_active === false
              ? 'failed'
              : 'idle';
    return (
        <section className="grid min-w-0 gap-3 px-4 pb-8 md:px-8 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <DashboardCard className="flex flex-col gap-5">
                <DetailHeader title="Agents" />
                <div className="grid grid-cols-3 gap-2">
                    {[
                        {
                            label: 'Sessions',
                            value: counts.sessions,
                            href: '/research',
                        },
                        {
                            label: 'Outputs',
                            value: counts.outputs,
                            href: '/outputs',
                        },
                        {
                            label: 'In review',
                            value: counts.pendingReview,
                            href: '/outputs',
                        },
                    ].map((item) => (
                        <Link
                            key={item.label}
                            href={item.href}
                            className="rounded-xl bg-[var(--kit-surface)] px-3 py-2.5 transition-colors hover:bg-[var(--kit-raised)]"
                        >
                            <span className="block text-2xl leading-none font-semibold tabular-nums">
                                {item.value}
                            </span>
                            <span className="mt-1.5 block text-[12px] text-[var(--kit-faint)]">
                                {item.label}
                            </span>
                        </Link>
                    ))}
                </div>
                <ul className="divide-y divide-[var(--kit-line)] text-[13px]">
                    <li className="flex items-center gap-3 py-2.5">
                        <StatusMark status={worker} />
                        <span className="flex-1">Worker</span>
                        <span className="text-[var(--kit-faint)]">
                            {agentStatus?.worker_active === undefined ||
                            agentStatus?.worker_active === null
                                ? '—'
                                : agentStatus.worker_active
                                  ? 'Active'
                                  : 'Inactive'}
                        </span>
                    </li>
                    <li className="flex items-center gap-3 py-2.5">
                        <StatusMark status={run?.status ?? 'idle'} />
                        <span className="min-w-0 flex-1 truncate">
                            Last run
                            {run?.symbol && (
                                <span className="ml-2 font-mono text-[12px] text-[var(--kit-faint)]">
                                    {run.symbol}
                                </span>
                            )}
                        </span>
                        <span className="text-[var(--kit-faint)] capitalize">
                            {run?.status?.replaceAll('_', ' ') ?? '—'}
                        </span>
                    </li>
                    <li className="flex items-center gap-3 py-2.5">
                        <StatusMark
                            status={
                                agentStatus?.schedule?.next_run
                                    ? 'waiting'
                                    : 'idle'
                            }
                        />
                        <span className="flex-1">Next run</span>
                        <span className="text-[var(--kit-faint)]">
                            {when(agentStatus?.schedule?.next_run) ?? '—'}
                        </span>
                    </li>
                    {latestDecision && (
                        <li className="py-2.5">
                            <div className="flex items-center gap-3">
                                <StatusMark status="done" />
                                <span className="flex-1">
                                    Decision ·{' '}
                                    <span className="font-mono">
                                        {displayValue(latestDecision.symbol)}
                                    </span>
                                </span>
                                <span className="kit-pill">
                                    {displayValue(latestDecision.rating)}
                                </span>
                            </div>
                            {typeof latestDecision.reason === 'string' && (
                                <p className="mt-1.5 line-clamp-2 pl-[26px] text-[12.5px] leading-5 text-[var(--kit-faint)]">
                                    {latestDecision.reason}
                                </p>
                            )}
                        </li>
                    )}
                </ul>
            </DashboardCard>
            <DashboardCard className="flex flex-col gap-3">
                <DetailHeader title="Team activity">
                    <Link
                        href="/outputs"
                        className="hub-link text-[var(--kit-soft)]"
                    >
                        All
                        <ArrowRight className="size-3.5" />
                    </Link>
                </DetailHeader>
                {recentOutputs.length ? (
                    <ul className="-mx-2">
                        {recentOutputs.map((output) => (
                            <li key={output.id}>
                                <Link
                                    href="/outputs"
                                    className="kit-row !py-2.5"
                                >
                                    <CollabAvatar
                                        name={output.author?.name ?? 'Research'}
                                        src={output.author?.avatar}
                                        size={28}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[14px] font-medium">
                                            {output.title}
                                        </span>
                                        <span className="block truncate text-[12px] text-[var(--kit-faint)] capitalize">
                                            {output.author?.name ?? 'Research'}{' '}
                                            · {output.kind}
                                        </span>
                                    </span>
                                    <StatusMark
                                        status={output.status}
                                        label={output.status.replaceAll(
                                            '_',
                                            ' ',
                                        )}
                                        className="capitalize max-sm:[&>span]:hidden"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="py-8 text-center text-[13px] text-[var(--kit-faint)]">
                        No activity yet
                    </p>
                )}
            </DashboardCard>
        </section>
    );
}
