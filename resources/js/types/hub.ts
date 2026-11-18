export type RecordData = Record<string, unknown>;
export type Output = {
    id: string | number;
    title: string;
    kind: string;
    status: string;
    content: string;
    created_at: string;
    version?: number;
    user_id?: number;
    external_id?: string | null;
    review_comment?: string | null;
    sources?: { url: string; title?: string }[];
    author?: { name: string; avatar?: string | null };
};
export type Run = {
    id: string | number;
    status: string;
    created_at?: string;
    pending_action?: { id: string; title: string; description?: string };
};
export type ResearchEvent = {
    id: number;
    research_run_id?: string | number;
    type: string;
    summary?: string;
    payload?: RecordData;
    created_at?: string;
};
export type BoardCard = {
    id: string;
    title: string;
    status: string;
    content?: string | null;
    version: number;
};
export type WorkspaceProposal = {
    id: string | number;
    status: 'pending' | 'applied' | 'rejected';
    payload: {
        operation: 'create_card' | 'update_card' | 'update_thesis';
        card_id?: string;
        title?: string;
        content?: string;
        status?: string;
        expected_version?: number;
    };
    created_at: string;
};
export type Session = {
    thesis?: string;
    thesis_version?: number;
    proposals?: WorkspaceProposal[];
    pinned?: boolean;
    id: string | number;
    title: string;
    updated_at: string;
    runs_count?: number;
    messages?: {
        id: string | number;
        role: string;
        content: string;
        created_at: string;
    }[];
    runs?: Run[];
    board?: BoardCard[];
    active_run?: { id: string; status: string } | null;
    last_event_id?: number;
    last_seen_event_id?: number;
    unread_event_count?: number;
    has_unread?: boolean;
};
export type EquityObservation = {
    observed_at: string;
    equity: string;
    source: 'native-paper-ledger';
    market_asof: string | null;
};
export type PortfolioSnapshot = {
    observed_at: string;
    source: string;
    accounts: RecordData[];
    holdings: RecordData[];
    activities: RecordData[];
    metrics: RecordData;
    history: EquityObservation[];
    history_provenance?: string;
    risk?: RecordData | null;
    watchlists?: RecordData[];
    capabilities?: { available: string[]; unavailable: string[] };
};

export type AgentStatus = {
    enabled?: boolean;
    worker_active?: boolean | null;
    schedule?: {
        state?: string;
        on_calendar?: string;
        next_run?: string | null;
    };
    latest_run?: {
        id?: string;
        status?: string;
        symbol?: string;
        started_at?: string;
        completed_at?: string;
    } | null;
    observed_at?: string;
};
