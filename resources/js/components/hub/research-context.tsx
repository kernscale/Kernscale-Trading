import ResearchBoard from '@/components/hub/research-board';
import ResearchThesis from '@/components/hub/research-thesis';
import { useForm } from '@inertiajs/react';
import { evidenceSources } from '@/components/hub/research-state';
import { Empty, Errors, Status } from '@/components/hub/shared';
import type { ResearchEvent, Session } from '@/types/hub';
export default function ResearchContext({
    session,
    events,
}: {
    session: Session | null;
    events: ResearchEvent[];
}) {
    const proposal = useForm({
        title: '',
        kind: 'strategy',
        content: '',
        session_id: session?.id,
    });
    const sources = evidenceSources(events);
    return (
        <>
            <p className="hub-eyebrow mb-5">Session context</p>
            <details open className="mb-6">
                <summary className="cursor-pointer text-xs font-medium">
                    Sources{' '}
                    <span className="ml-2 text-muted-foreground">
                        {sources.length}
                    </span>
                </summary>
                <div className="mt-3 space-y-3">
                    {sources.length ? (
                        sources.map((source) => (
                            <div key={source.url} className="text-xs">
                                <a
                                    href={source.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="hub-link break-all"
                                >
                                    {source.citationIndex !== undefined
                                        ? `[${source.citationIndex}] `
                                        : ''}
                                    {source.title ?? source.url}
                                </a>
                                {source.evidenceId && (
                                    <span className="mt-1 block text-[10px] text-muted-foreground">
                                        Evidence {source.evidenceId}
                                    </span>
                                )}
                            </div>
                        ))
                    ) : (
                        <p className="text-xs leading-6 text-muted-foreground">
                            Cited evidence will appear here when returned by a
                            run.
                        </p>
                    )}
                </div>
            </details>
            <div className="mb-6 border-y border-white/10 py-4">
                <p className="mb-2 text-xs font-medium">Context usage</p>
                <p className="text-xs leading-5 text-muted-foreground">
                    Unavailable — no current context occupancy reported.
                </p>
            </div>
            {session && <ResearchThesis session={session} />}
            {session && <ResearchBoard session={session} />}
            {session && (
                <details>
                    <summary className="cursor-pointer text-xs font-medium">
                        Create strategy proposal
                    </summary>
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            proposal.post('/outputs', {
                                onSuccess: () =>
                                    proposal.reset('title', 'content'),
                            });
                        }}
                    >
                        <input
                            aria-label="Strategy proposal title"
                            required
                            placeholder="Proposal title"
                            className="hub-input text-xs"
                            value={proposal.data.title}
                            onChange={(e) =>
                                proposal.setData('title', e.target.value)
                            }
                        />
                        <textarea
                            aria-label="Strategy proposal content"
                            required
                            placeholder="Thesis, evidence and risks…"
                            rows={5}
                            className="hub-input text-xs"
                            value={proposal.data.content}
                            onChange={(e) =>
                                proposal.setData('content', e.target.value)
                            }
                        />
                        <Errors errors={proposal.errors} />
                        <button
                            className="hub-button w-full"
                            disabled={proposal.processing}
                        >
                            Save private draft
                        </button>
                        <Status>Owner review before publication</Status>
                    </form>
                </details>
            )}
            {!session && <Empty title="Select a session" />}
        </>
    );
}
