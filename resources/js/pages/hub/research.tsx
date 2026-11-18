import '@/components/hub/research.css';
import { Head, router, useForm } from '@inertiajs/react';
import {
    FileStack,
    Link2,
    MessageSquareText,
    Search,
    SquarePen,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import SessionHeader from '@/components/hub/session-header';
import SessionList from '@/components/hub/session-list';
import ResearchContext from '@/components/hub/research-context';
import ResearchActivity from '@/components/hub/research-activity';
import ResearchKanban from '@/components/hub/research-kanban';
import {
    AttachButton,
    AttachmentTray,
    useAttachments,
} from '@/components/hub/research-attachments';
import { useSessionRead } from '@/components/hub/use-session-read';
import ResearchClarification, {
    openClarification,
} from '@/components/hub/research-clarification';
import {
    ThreadMessages,
    ThreadWelcome,
} from '@/components/hub/research-thread';
import { Errors } from '@/components/hub/shared';
import { Composer, type MentionGroup } from '@/components/kit/composer';
import { Segment } from '@/components/kit/micro';
import {
    MessageScrollerProvider,
    MessageScroller,
    MessageScrollerViewport,
    MessageScrollerContent,
    MessageScrollerButton,
} from '@/components/ui/message-scroller';
import type { BoardCard, ResearchEvent, Session } from '@/types/hub';
import {
    activeRunStates as activeStates,
    evidenceSources,
    mergeEvents,
    runStatusLabel,
    researchIntent,
    completeResearchIntent,
    type ResearchIntent,
} from '@/components/hub/research-state';
type Panel = 'sessions' | 'conversation' | 'context';
export default function Research({
    sessions = [],
    activeSession = null,
    events = [],
}: {
    sessions?: Session[];
    activeSession?: Session | null;
    events?: ResearchEvent[];
}) {
    const [panel, setPanel] = useState<Panel>('conversation');
    const [view, setView] = useState<'chat' | 'board'>('chat');
    const [contextOpen, setContextOpenState] = useState(() => {
        try {
            return localStorage.getItem('research.context') === 'open';
        } catch {
            return false;
        }
    });
    const setContextOpen = (update: (open: boolean) => boolean) =>
        setContextOpenState((open) => {
            const next = update(open);
            try {
                localStorage.setItem(
                    'research.context',
                    next ? 'open' : 'closed',
                );
            } catch {
                /* storage unavailable */
            }
            return next;
        });
    const [query, setQuery] = useState('');
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const sendIntents = useRef(new Map<string, ResearchIntent>());
    const [pollError, setPollError] = useState(false);
    const [observedStatus, setObservedStatus] = useState<string | null>(null);
    const [liveEvents, setLiveEvents] = useState(events);
    const sessionForm = useForm({ title: 'Untitled research' });
    const prompt = useForm({ prompt: '', idempotency_key: '' });
    const attachments = useAttachments(activeSession?.id);
    const key = String(activeSession?.id ?? 'new');
    const draft = drafts[key] ?? '';
    const activeRun = activeSession?.runs?.find((run) =>
        activeStates.includes(run.status),
    );
    const latestRun = [...(activeSession?.runs ?? [])].sort(
        (a, b) =>
            Date.parse(b.created_at ?? '') - Date.parse(a.created_at ?? ''),
    )[0];
    useEffect(() => {
        setLiveEvents(events);
        setPollError(false);
    }, [events, activeSession?.id]);
    useEffect(() => {
        setObservedStatus(null);
        if (!activeRun) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;
        const controller = new AbortController();
        let cursor = events.at(-1)?.id ?? 0;
        const poll = async () => {
            try {
                const response = await fetch(
                    `/research/runs/${activeRun.id}/events?after=${cursor}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok)
                    throw new Error('Event connection unavailable');
                const data = (await response.json()) as {
                    events?: ResearchEvent[];
                    status?: string;
                    run?: { status: string };
                };
                if (cancelled) return;
                const incoming = data.events ?? [];
                if (
                    incoming.some(
                        (event) =>
                            event.id > cursor &&
                            event.type === 'workspace_proposal',
                    )
                )
                    router.reload({ only: ['activeSession'] });
                setLiveEvents((previous) => mergeEvents(previous, incoming));
                cursor = Math.max(cursor, ...incoming.map((event) => event.id));
                setPollError(false);
                const status = data.status ?? data.run?.status;
                if (status) setObservedStatus(status);
                if (status && !activeStates.includes(status)) {
                    router.reload({
                        only: ['activeSession', 'events', 'sessions'],
                    });
                    return;
                }
            } catch {
                if (!cancelled) setPollError(true);
            }
            if (!cancelled) timer = setTimeout(poll, 2000);
        };
        void poll();
        return () => {
            cancelled = true;
            controller.abort();
            clearTimeout(timer);
        };
    }, [activeRun?.id, events]);
    const clarification = openClarification(liveEvents, activeRun?.id);
    useSessionRead(activeSession, sessions, liveEvents, view === 'chat');
    const runStatus = activeRun
        ? (observedStatus ?? activeRun.status)
        : latestRun?.status;
    const mentions = useMemo<MentionGroup[]>(
        () => [
            {
                label: 'Board',
                items: (activeSession?.board ?? []).map((card) => ({
                    id: `card-${card.id}`,
                    label: card.title,
                    meta: card.status.replaceAll('_', ' '),
                    icon: <FileStack />,
                    tone: 'var(--chart-2)',
                })),
            },
            {
                label: 'Sources',
                items: evidenceSources(liveEvents).map((source) => ({
                    id: `source-${source.url}`,
                    label: source.title ?? new URL(source.url).hostname,
                    meta: new URL(source.url).hostname,
                    icon: <Link2 />,
                    tone: 'var(--chart-4)',
                })),
            },
            {
                label: 'Sessions',
                items: sessions
                    .filter((session) => session.id !== activeSession?.id)
                    .map((session) => ({
                        id: `session-${session.id}`,
                        label: session.title,
                        meta: `${session.runs_count ?? 0} runs`,
                        icon: <MessageSquareText />,
                        tone: 'var(--chart-3)',
                    })),
            },
        ],
        [activeSession, liveEvents, sessions],
    );
    const createSession = () => sessionForm.post('/research/sessions');
    const send = () => {
        if (!activeSession || !draft.trim() || activeRun) return;
        const attachmentIds = attachments.selected;
        const intentKey = researchIntent(
            sendIntents.current,
            key,
            `${draft}\u0000${attachmentIds.join(',')}`,
        );
        prompt.transform(() => ({
            prompt: draft,
            idempotency_key: intentKey,
            ...(attachmentIds.length ? { attachment_ids: attachmentIds } : {}),
        }));
        prompt.post(`/research/sessions/${activeSession.id}/runs`, {
            preserveScroll: true,
            onSuccess: () => {
                completeResearchIntent(sendIntents.current, key, intentKey);
                attachments.clear();
                setDrafts((previous) =>
                    previous[key] === draft
                        ? { ...previous, [key]: '' }
                        : previous,
                );
            },
        });
    };
    const researchCard = (card: BoardCard) => {
        if (!activeSession) return;
        const text = [`Research this board card: ${card.title}`, card.content]
            .filter(Boolean)
            .join('\n\n');
        setView('chat');
        if (activeRun) {
            setDraft(text);
            return;
        }
        router.post(
            `/research/sessions/${activeSession.id}/runs`,
            { prompt: text, idempotency_key: `card-${card.id}-v${card.version}` },
            { preserveScroll: true, onError: () => setDraft(text) },
        );
    };
    const setDraft = (next: string) => {
        if (next !== draft) sendIntents.current.delete(key);
        setDrafts((previous) => ({ ...previous, [key]: next }));
    };
    const hasMessages = !!activeSession?.messages?.length;
    return (
        <MessageScrollerProvider>
            <Head title="Research" />
            <div className="research-workspace-page">
                <Segment<Panel>
                    className="research-tabs"
                    label="Research panels"
                    value={panel}
                    onChange={setPanel}
                    options={[
                        {
                            value: 'sessions',
                            label: 'Sessions',
                            count: sessions.length,
                        },
                        { value: 'conversation', label: 'Conversation' },
                        { value: 'context', label: 'Context' },
                    ]}
                />
                <div
                    className="research-grid"
                    data-context={contextOpen ? '' : undefined}
                >
                    <aside
                        className={`research-pane research-sessions ${panel !== 'sessions' ? 'mobile-hidden' : ''}`}
                    >
                        <div className="mb-2 flex items-center gap-1">
                            <label className="relative block min-w-0 flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-[var(--kit-faint)]" />
                                <input
                                    aria-label="Search sessions"
                                    placeholder="Search"
                                    className="research-search-input"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                />
                            </label>
                            <button
                                type="button"
                                className="kit-tool"
                                aria-label="New session"
                                title="New session"
                                disabled={sessionForm.processing}
                                onClick={createSession}
                            >
                                <SquarePen className="size-4" />
                            </button>
                        </div>
                        <Errors errors={sessionForm.errors} />
                        <SessionList
                            sessions={sessions}
                            selected={activeSession?.id}
                            query={query}
                            onSelect={() => setPanel('conversation')}
                        />
                    </aside>
                    <section
                        className={`research-pane research-conversation ${panel !== 'conversation' ? 'mobile-hidden' : ''}`}
                    >
                        <SessionHeader
                            key={key}
                            session={activeSession}
                            status={
                                runStatus &&
                                (activeRun ||
                                    /fail|error|interrupt|cancel/.test(runStatus))
                                    ? runStatusLabel(runStatus)
                                    : undefined
                            }
                            rawStatus={runStatus}
                            onContext={() => setContextOpen((open) => !open)}
                            actions={
                                activeSession && (
                                    <Segment
                                        className="[&_button]:!h-6 [&_button]:!px-2.5"
                                        label="Research view"
                                        value={view}
                                        onChange={setView}
                                        options={[
                                            { value: 'chat', label: 'Chat' },
                                            {
                                                value: 'board',
                                                label: 'Board',
                                                count: activeSession.board
                                                    ?.length,
                                            },
                                        ]}
                                    />
                                )
                            }
                        />
                        {view === 'board' && activeSession ? (
                            <div className="min-h-0 flex-1 overflow-auto p-3 md:p-5">
                                <ResearchKanban
                                    session={activeSession}
                                    onResearch={researchCard}
                                />
                            </div>
                        ) : (
                            <>
                                <MessageScroller className="min-h-0 flex-1">
                                    <MessageScrollerViewport className="research-scroll">
                                        <MessageScrollerContent>
                                            <div className="kit-thread">
                                                {hasMessages &&
                                                activeSession ? (
                                                    <ThreadMessages
                                                        session={activeSession}
                                                    />
                                                ) : (
                                                    <ThreadWelcome
                                                        session={activeSession}
                                                        creating={
                                                            sessionForm.processing
                                                        }
                                                        onCreate={createSession}
                                                        onStarter={setDraft}
                                                    />
                                                )}
                                                <ResearchActivity
                                                    events={liveEvents}
                                                    streaming={!!activeRun}
                                                    activeRunId={activeRun?.id}
                                                    startedAt={
                                                        activeRun?.created_at
                                                    }
                                                    label={
                                                        runStatus ===
                                                            'running' ||
                                                        !runStatus
                                                            ? 'Researching'
                                                            : runStatusLabel(
                                                                  runStatus,
                                                              )
                                                    }
                                                />
                                                {activeRun &&
                                                    clarification && (
                                                        <ResearchClarification
                                                            key={
                                                                clarification.request_id
                                                            }
                                                            runId={activeRun.id}
                                                            request={
                                                                clarification
                                                            }
                                                        />
                                                    )}
                                                {pollError && (
                                                    <p
                                                        role="status"
                                                        className="mt-4 text-xs text-amber-200"
                                                    >
                                                        Activity connection
                                                        interrupted.
                                                        Reconnecting; your run
                                                        continues on the server.
                                                    </p>
                                                )}
                                            </div>
                                        </MessageScrollerContent>
                                    </MessageScrollerViewport>
                                    <MessageScrollerButton />
                                </MessageScroller>
                                <div className="research-composer">
                                    <div className="kit-thread">
                                        <AttachmentTray
                                            attachments={attachments}
                                        />
                                        <Composer
                                            id="research-prompt"
                                            label="Research question"
                                            sendLabel="Send research question"
                                            value={draft}
                                            onChange={setDraft}
                                            onSubmit={send}
                                            disabled={!activeSession}
                                            processing={prompt.processing}
                                            busy={!!activeRun}
                                            onStop={() =>
                                                activeRun &&
                                                router.post(
                                                    `/research/runs/${activeRun.id}/cancel`,
                                                )
                                            }
                                            mentions={mentions}
                                            tools={
                                                <AttachButton
                                                    attachments={attachments}
                                                    disabled={!activeSession}
                                                />
                                            }
                                            placeholder={
                                                activeSession
                                                    ? hasMessages
                                                        ? 'Reply…'
                                                        : 'Ask anything · @ to mention'
                                                    : 'Start a session to begin'
                                            }
                                        />
                                        <Errors errors={prompt.errors} />
                                    </div>
                                </div>
                            </>
                        )}
                    </section>
                    <aside
                        className={`research-pane research-context ${panel !== 'context' ? 'mobile-hidden' : ''}`}
                    >
                        <ResearchContext
                            key={key}
                            session={activeSession}
                            events={liveEvents}
                            files={attachments.files}
                        />
                    </aside>
                </div>
            </div>
        </MessageScrollerProvider>
    );
}
