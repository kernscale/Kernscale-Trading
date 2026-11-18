import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ExternalLink, MessagesSquare, Send, X } from 'lucide-react';
import { CollabAvatar, CopyButton } from '@/components/kit/collab';
import Markdown from '@/components/hub/markdown';
import ReportFrame, { isHtmlDocument } from '@/components/hub/report-frame';
import OutputHistory, { type Revision } from '@/components/hub/output-history';
import { Errors } from '@/components/hub/shared';
import { Segment, StatusMark } from '@/components/kit/micro';
import type { Output } from '@/types/hub';

const label = (value: string) => value.replaceAll('_', ' ');

export default function OutputDetail({
    output,
    userId,
    owner,
    onClose,
}: {
    output: Output;
    userId?: number;
    owner: boolean;
    onClose: () => void;
}) {
    const edit = useForm({
        title: output.title,
        content: output.content,
        version: output.version ?? 1,
    });
    const review = useForm({
        decision: 'publish',
        comment: '',
        version: output.version ?? 1,
    });
    const mine = output.user_id === userId;
    const [viewing, setViewing] = useState<Revision | null>(null);
    const shown = viewing?.snapshot ?? output;
    const editable =
        !viewing &&
        mine &&
        ['draft', 'changes_requested'].includes(output.status) &&
        !output.external_id;
    return (
        <article className="flex min-h-0 flex-col">
            <header className="flex items-start gap-3 border-b border-[var(--kit-line)] px-5 py-4 md:px-8">
                <div className="min-w-0 flex-1">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                        <span className="kit-pill">
                            <StatusMark status={output.status} size={12} />
                            {label(output.status)}
                        </span>
                        <span className="kit-pill capitalize">
                            {label(output.kind)}
                        </span>
                        {mine ? (
                            <OutputHistory
                                key={output.id}
                                output={output}
                                viewing={viewing}
                                onView={setViewing}
                            />
                        ) : (
                            output.version && (
                                <span className="kit-pill">
                                    v{output.version}
                                </span>
                            )
                        )}
                    </div>
                    <h2 className="text-xl leading-snug font-medium tracking-tight md:text-2xl">
                        {shown.title}
                    </h2>
                    <p className="mt-2 flex items-center gap-2 text-[13px] text-[var(--kit-faint)]">
                        <CollabAvatar
                            name={output.author?.name ?? 'Research'}
                            src={output.author?.avatar}
                            size={20}
                        />
                        {output.author?.name ?? 'Research'} ·{' '}
                        {new Date(output.created_at).toLocaleDateString(
                            undefined,
                            { month: 'short', day: 'numeric', year: 'numeric' },
                        )}
                    </p>
                </div>
                <CopyButton text={shown.content} label="Copy document" />
                {output.status === 'published' && (
                    <button
                        type="button"
                        className="kit-tool"
                        aria-label="Discuss in community"
                        title="Discuss in community"
                        onClick={() =>
                            router.visit(
                                `/community?about=${encodeURIComponent(output.title)}`,
                            )
                        }
                    >
                        <MessagesSquare className="size-4" />
                    </button>
                )}
                <button
                    type="button"
                    className="kit-tool"
                    aria-label="Close document"
                    onClick={onClose}
                >
                    <X className="size-4" />
                </button>
            </header>
            <div className="space-y-6 px-5 py-6 md:px-8">
                {viewing && (
                    <div className="flex items-center gap-3 rounded-xl border border-[var(--kit-line)] bg-[var(--kit-surface)] px-4 py-2.5 text-[13px]">
                        <span className="flex-1 text-[var(--kit-soft)]">
                            Viewing saved version v{viewing.version}
                        </span>
                        <button
                            type="button"
                            className="hub-link text-foreground"
                            onClick={() => setViewing(null)}
                        >
                            Back to current
                        </button>
                    </div>
                )}
                {!viewing && output.review_comment && (
                    <div className="rounded-xl border border-amber-300/20 bg-amber-300/5 px-4 py-3 text-[13px] leading-6 text-amber-100">
                        <span className="font-medium">Review feedback · </span>
                        {output.review_comment}
                    </div>
                )}
                {isHtmlDocument(shown.content) ? (
                    <ReportFrame html={shown.content} title={shown.title} />
                ) : (
                    <div className="max-w-[44rem] text-[15px] leading-7">
                        <Markdown>{shown.content}</Markdown>
                    </div>
                )}
                {!!shown.sources?.length && (
                    <div>
                        <p className="mb-2 text-[13px] font-medium">Sources</p>
                        <div className="flex flex-wrap gap-2">
                            {shown.sources.map((source) => (
                                <a
                                    key={source.url}
                                    className="kit-pill max-w-full hover:text-foreground"
                                    href={
                                        /^https?:\/\//i.test(source.url)
                                            ? source.url
                                            : undefined
                                    }
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink className="size-3" />
                                    <span className="truncate">
                                        {source.title ?? source.url}
                                    </span>
                                </a>
                            ))}
                        </div>
                    </div>
                )}
                {editable && (
                    <form
                        className="space-y-3 rounded-2xl border border-[var(--kit-line)] bg-[var(--kit-surface)] p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            edit.patch(`/outputs/${output.id}`, {
                                onSuccess: onClose,
                            });
                        }}
                    >
                        <p className="text-[13px] font-medium">Revise draft</p>
                        <input
                            required
                            aria-label="Title"
                            className="hub-input"
                            value={edit.data.title}
                            onChange={(e) =>
                                edit.setData('title', e.target.value)
                            }
                        />
                        <textarea
                            required
                            aria-label="Revise draft"
                            rows={8}
                            className="hub-input"
                            value={edit.data.content}
                            onChange={(e) =>
                                edit.setData('content', e.target.value)
                            }
                        />
                        <Errors errors={edit.errors} />
                        <div className="flex flex-wrap justify-end gap-2">
                            <button
                                disabled={edit.processing}
                                className="hub-button"
                            >
                                Save revision
                            </button>
                            <button
                                type="button"
                                className="hub-button primary"
                                onClick={() =>
                                    router.post(
                                        `/outputs/${output.id}/submit`,
                                        {},
                                        { onSuccess: onClose },
                                    )
                                }
                            >
                                <Send className="size-3.5" />
                                Submit for owner review
                            </button>
                        </div>
                    </form>
                )}
                {!viewing &&
                    mine &&
                    !editable &&
                    ['draft', 'changes_requested'].includes(output.status) && (
                        <button
                            className="hub-button primary"
                            onClick={() =>
                                router.post(
                                    `/outputs/${output.id}/submit`,
                                    {},
                                    { onSuccess: onClose },
                                )
                            }
                        >
                            Submit for owner review
                        </button>
                    )}
                {!viewing && owner && output.status === 'in_review' && (
                    <form
                        className="space-y-3 rounded-2xl border border-[var(--kit-line)] bg-[var(--kit-surface)] p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            review.post(`/outputs/${output.id}/review`, {
                                onSuccess: onClose,
                            });
                        }}
                    >
                        <div>
                            <p className="text-[13px] font-medium">
                                Confirm publication review
                            </p>
                            <p className="mt-1 text-xs leading-5 text-[var(--kit-faint)]">
                                Publish shares this output with approved
                                members. Request changes keeps it in the review
                                workflow.
                            </p>
                        </div>
                        <Segment
                            label="Review decision"
                            value={review.data.decision}
                            onChange={(value) =>
                                review.setData('decision', value)
                            }
                            options={[
                                { value: 'publish', label: 'Publish' },
                                {
                                    value: 'changes_requested',
                                    label: 'Request changes',
                                },
                            ]}
                        />
                        <textarea
                            aria-label="Review feedback"
                            placeholder="Feedback for the author (optional)"
                            rows={3}
                            className="hub-input"
                            value={review.data.comment}
                            onChange={(e) =>
                                review.setData('comment', e.target.value)
                            }
                        />
                        <Errors errors={review.errors} />
                        <div className="flex justify-end">
                            <button
                                className="hub-button primary"
                                disabled={review.processing}
                            >
                                Confirm review
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </article>
    );
}
