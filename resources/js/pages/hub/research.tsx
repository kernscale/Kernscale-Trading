import '@/components/hub/research.css';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowRight, Plus, Search, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import LatticeLoader from '@/components/hub/LatticeLoader';
import Markdown from '@/components/hub/markdown';
import SessionHeader from '@/components/hub/session-header';
import SessionList from '@/components/hub/session-list';
import ResearchContext from '@/components/hub/research-context';
import { Empty, Errors, PageHeading, Status } from '@/components/hub/shared';
import {
    Message,
    MessageContent,
    MessageHeader,
} from '@/components/ui/message';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { InputGroup, InputGroupTextarea } from '@/components/ui/input-group';
import {
    MessageScrollerProvider,
    MessageScroller,
    MessageScrollerViewport,
    MessageScrollerContent,
    MessageScrollerItem,
    MessageScrollerButton,
} from '@/components/ui/message-scroller';
import ResearchActivity from '@/components/hub/research-activity';
import type { ResearchEvent, Session } from '@/types/hub';
import {
    activeRunStates as activeStates,
    mergeEvents,
    runStatusLabel,
    researchIntent,
    completeResearchIntent,
    type ResearchIntent,
} from '@/components/hub/research-state';
export default function Research({
    sessions = [],
    activeSession = null,
    events = [],
}: {
    sessions?: Session[];
    activeSession?: Session | null;
    events?: ResearchEvent[];
}) {
    const [panel, setPanel] = useState('conversation');
    const [query, setQuery] = useState('');
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const sendIntents = useRef(new Map<string, ResearchIntent>());
    const [pollError, setPollError] = useState(false);
    const [observedStatus, setObservedStatus] = useState<string | null>(null);
    const [liveEvents, setLiveEvents] = useState(events);
    const sessionForm = useForm({ title: 'Untitled research' });
    const prompt = useForm({ prompt: '', idempotency_key: '' });
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
    return (
        <MessageScrollerProvider>
            <Head title="Research" />
            <div className="research-workspace-page">
                <PageHeading
                    eyebrow="Private research workspace"
                    title="Research"
                >
                    <Status>Go / DeepSeek · R1 tools</Status>
                </PageHeading>
                <div
                    className="research-tabs"
                    role="tablist"
                    aria-label="Research panels"
                >
                    {['sessions', 'conversation', 'context'].map((tab) => (
                        <button
                            key={tab}
                            role="tab"
                            aria-selected={panel === tab}
                            onClick={() => setPanel(tab)}
                            className={`hub-button capitalize ${panel === tab ? 'primary' : ''}`}
                        >
                            {tab}
                        </button>
                    ))}
                </div>
                <div className="research-grid">
                    <aside
                        className={`research-pane research-sessions ${panel !== 'sessions' ? 'mobile-hidden' : ''}`}
                    >
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                sessionForm.post('/research/sessions');
                            }}
                        >
                            <button
                                className="hub-button mb-4 w-full"
                                disabled={sessionForm.processing}
                            >
                                <Plus className="size-4" />
                                New session
                            </button>
                            <Errors errors={sessionForm.errors} />
                        </form>
                        <label className="relative mb-6 block">
                            <Search className="absolute top-3 left-3 size-3.5 text-muted-foreground" />
                            <input
                                aria-label="Search sessions"
                                placeholder="Search sessions"
                                className="hub-input research-search-input text-xs"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </label>
                        <SessionList
                            sessions={sessions}
                            selected={activeSession?.id}
                            query={query}
                            running={!!activeRun}
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
                                (activeRun ?? latestRun)
                                    ? runStatusLabel(
                                          activeRun
                                              ? (observedStatus ??
                                                    activeRun.status)
                                              : latestRun.status,
                                      )
                                    : undefined
                            }
                        />
                        <MessageScroller className="min-h-0 flex-1">
                            <MessageScrollerViewport className="research-scroll">
                                <MessageScrollerContent>
                                    {activeSession?.messages?.length ? (
                                        <div className="space-y-7">
                                            {activeSession.messages.map(
                                                (message) => (
                                                    <MessageScrollerItem
                                                        key={message.id}
                                                        messageId={String(
                                                            message.id,
                                                        )}
                                                        scrollAnchor={
                                                            message.role ===
                                                            'user'
                                                        }
                                                    >
                                                        <Message
                                                            align={
                                                                message.role ===
                                                                'user'
                                                                    ? 'end'
                                                                    : 'start'
                                                            }
                                                        >
                                                            <MessageContent>
                                                                <MessageHeader>
                                                                    {message.role ===
                                                                    'assistant'
                                                                        ? 'Research'
                                                                        : message.role}
                                                                </MessageHeader>
                                                                <Bubble
                                                                    variant={
                                                                        message.role ===
                                                                        'user'
                                                                            ? 'muted'
                                                                            : 'ghost'
                                                                    }
                                                                >
                                                                    <BubbleContent className="text-sm leading-7">
                                                                        {message.role ===
                                                                        'assistant' ? (
                                                                            <Markdown>
                                                                                {
                                                                                    message.content
                                                                                }
                                                                            </Markdown>
                                                                        ) : (
                                                                            message.content
                                                                        )}
                                                                    </BubbleContent>
                                                                </Bubble>
                                                            </MessageContent>
                                                        </Message>
                                                    </MessageScrollerItem>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <Empty title="Follow a question worth asking">
                                            Create or select a session to
                                            investigate markets, test a thesis
                                            and keep the evidence close.
                                        </Empty>
                                    )}
                                    <ResearchActivity
                                        events={liveEvents}
                                        streaming={!!activeRun}
                                        activeRunId={activeRun?.id}
                                    />
                                    {activeRun && (
                                        <div className="mt-5 text-xs text-emerald-200">
                                            <LatticeLoader
                                                label={
                                                    (observedStatus ??
                                                        activeRun.status) ===
                                                    'running'
                                                        ? 'Researching'
                                                        : runStatusLabel(
                                                              observedStatus ??
                                                                  activeRun.status,
                                                          )
                                                }
                                                showTimer={
                                                    !!activeRun.created_at &&
                                                    !Number.isNaN(
                                                        Date.parse(
                                                            activeRun.created_at,
                                                        ),
                                                    )
                                                }
                                                elapsed={
                                                    activeRun.created_at
                                                        ? Math.max(
                                                              0,
                                                              (Date.now() -
                                                                  Date.parse(
                                                                      activeRun.created_at,
                                                                  )) /
                                                                  1000,
                                                          )
                                                        : undefined
                                                }
                                                style={{}}
                                            />
                                        </div>
                                    )}
                                    {pollError && (
                                        <p
                                            role="status"
                                            className="mt-4 text-xs text-amber-200"
                                        >
                                            Activity connection interrupted.
                                            Reconnecting; your run continues on
                                            the server.
                                        </p>
                                    )}
                                </MessageScrollerContent>
                            </MessageScrollerViewport>
                            <MessageScrollerButton />
                        </MessageScroller>
                        <form
                            className="research-composer"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (
                                    !activeSession ||
                                    !draft.trim() ||
                                    activeRun
                                )
                                    return;
                                const intentKey = researchIntent(
                                    sendIntents.current,
                                    key,
                                    draft,
                                );
                                prompt.transform(() => ({
                                    prompt: draft,
                                    idempotency_key: intentKey,
                                }));
                                prompt.post(
                                    `/research/sessions/${activeSession.id}/runs`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            completeResearchIntent(
                                                sendIntents.current,
                                                key,
                                                intentKey,
                                            );
                                            setDrafts((previous) =>
                                                previous[key] === draft
                                                    ? { ...previous, [key]: '' }
                                                    : previous,
                                            );
                                        },
                                    },
                                );
                            }}
                        >
                            <label
                                className="sr-only"
                                htmlFor="research-prompt"
                            >
                                Research question
                            </label>
                            <InputGroup className="h-auto rounded-lg">
                                <InputGroupTextarea
                                    id="research-prompt"
                                    className="hub-input resize-none border-0 bg-transparent"
                                    rows={3}
                                    placeholder={
                                        activeSession
                                            ? 'Ask a question. Develop a thesis. Examine the evidence…'
                                            : 'Select or create a session to begin.'
                                    }
                                    disabled={!activeSession}
                                    value={draft}
                                    onChange={(e) => {
                                        const nextDraft = e.target.value;
                                        if (nextDraft !== draft)
                                            sendIntents.current.delete(key);
                                        setDrafts((previous) => ({
                                            ...previous,
                                            [key]: nextDraft,
                                        }));
                                    }}
                                />
                            </InputGroup>
                            <Errors errors={prompt.errors} />
                            <div className="mt-3 flex items-center justify-between gap-3">
                                <span className="text-[10px] text-muted-foreground">
                                    Private session · Evidence-led research
                                </span>
                                {activeRun ? (
                                    <button
                                        type="button"
                                        className="hub-button"
                                        onClick={() =>
                                            router.post(
                                                `/research/runs/${activeRun.id}/cancel`,
                                            )
                                        }
                                    >
                                        <Square className="size-3" />
                                        Stop
                                    </button>
                                ) : (
                                    <button
                                        className="hub-button primary"
                                        aria-label="Send research question"
                                        disabled={
                                            !activeSession ||
                                            !draft.trim() ||
                                            prompt.processing
                                        }
                                    >
                                        Send
                                        <ArrowRight className="size-4" />
                                    </button>
                                )}
                            </div>
                        </form>
                    </section>
                    <aside
                        className={`research-pane research-context ${panel !== 'context' ? 'mobile-hidden' : ''}`}
                    >
                        <ResearchContext
                            key={key}
                            session={activeSession}
                            events={liveEvents}
                        />
                    </aside>
                </div>
            </div>
        </MessageScrollerProvider>
    );
}
