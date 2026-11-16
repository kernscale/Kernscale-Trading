import { Head } from '@inertiajs/react';
import NativeDashboard from '@/components/capitalio/native-dashboard';
import ResearchOperations from '@/components/capitalio/operations';
import type {
    AgentStatus,
    Output,
    PortfolioSnapshot,
    RecordData,
} from '@/types/hub';
export default function Dashboard({
    portfolio = null,
    agentStatus = null,
    latestDecision = null,
    counts = { sessions: 0, outputs: 0, pendingReview: 0 },
    recentOutputs = [],
}: {
    portfolio?: PortfolioSnapshot | null;
    agentStatus?: AgentStatus | null;
    latestDecision?: RecordData | null;
    counts?: { sessions: number; outputs: number; pendingReview: number };
    recentOutputs?: Output[];
}) {
    return (
        <>
            <Head title="Research overview" />
            <NativeDashboard snapshot={portfolio} />
            <ResearchOperations
                agentStatus={agentStatus}
                latestDecision={latestDecision}
                counts={counts}
                recentOutputs={recentOutputs}
            />
        </>
    );
}
