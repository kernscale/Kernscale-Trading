import ResearchThesis from '@/components/hub/research-thesis';
import { useForm } from '@inertiajs/react';
import { evidenceSources } from '@/components/hub/research-state';
import { Download, FileText, Link2 } from 'lucide-react';
import {
    fileSize,
    type AttachmentReceipt,
} from '@/components/hub/research-attachments';
import { Empty, Errors, Status } from '@/components/hub/shared';
import { Quiet } from '@/components/kit/micro';
import type { ResearchEvent, Session } from '@/types/hub';
export default function ResearchContext({
    session,
    events,
    files = [],
}: {
    session: Session | null;
    events: ResearchEvent[];
    files?: AttachmentReceipt[];
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
            <details open className="mb-6">
                <summary className="cursor-pointer text-[13px] font-medium">
                    Sources{' '}
                    <span className="kit-pill ml-1.5 !h-5">
                        {sources.length}
                    </span>
                </summary>
                <div className="mt-3 space-y-1">
                    {sources.length ? (
                        sources.map((source) => (
                            <div
                                key={source.url}
                                className="kit-row !items-start !p-2 text-xs"
                            >
                                <span className="kit-mention-icon mt-0.5 grid size-6 shrink-0 place-items-center rounded-md bg-[color-mix(in_oklab,var(--chart-4)_16%,transparent)] text-[var(--chart-4)]">
                                    <Link2 className="size-3.5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <a
                                        href={source.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="line-clamp-2 break-all text-foreground hover:underline"
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
                                </span>
                            </div>
                        ))
                    ) : (
                        <Quiet>
                            Cited evidence appears here when a run returns it.
                        </Quiet>
                    )}
                </div>
            </details>
            {session && files.length > 0 && (
                <details open className="mb-6">
                    <summary className="cursor-pointer text-[13px] font-medium">
                        Files{' '}
                        <span className="kit-pill ml-1.5 !h-5">
                            {files.length}
                        </span>
                    </summary>
                    <div className="mt-3 space-y-1">
                        {files.map((file) => (
                            <a
                                key={file.id}
                                href={`/research/sessions/${session.id}/attachments/${file.id}`}
                                className="kit-row group !p-2 text-xs"
                                title={`Download ${file.name}`}
                            >
                                <span className="grid size-6 shrink-0 place-items-center rounded-md bg-[var(--kit-raised)]">
                                    <FileText className="size-3.5" />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-foreground">
                                    {file.name}
                                </span>
                                <span className="text-[var(--kit-faint)] tabular-nums">
                                    {fileSize(file.size)}
                                </span>
                                <Download className="size-3.5 opacity-0 transition-opacity group-hover:opacity-70" />
                            </a>
                        ))}
                    </div>
                </details>
            )}
            {session && <ResearchThesis session={session} />}
            {session && (
                <details>
                    <summary className="cursor-pointer text-[13px] font-medium">
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
