import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Empty,
    DataTable,
    Errors,
    PageHeading,
    Panel,
    Status,
} from '@/components/hub/shared';
import {
    Item,
    ItemContent,
    ItemTitle,
    ItemActions,
} from '@/components/ui/item';
import Markdown from '@/components/hub/markdown';
import type { Output, RecordData } from '@/types/hub';
export default function Outputs({
    outputs = [],
    runs = [],
}: {
    outputs?: Output[];
    runs?: RecordData[];
}) {
    const [selected, setSelected] = useState<Output | null>(null);
    const form = useForm({ title: '', kind: 'research', content: '' });
    const edit = useForm({ title: '', content: '', version: 1 });
    const review = useForm({ decision: 'publish', comment: '' });
    const auth = usePage().props.auth as {
        roles?: string[];
        user?: { id?: number; roles?: (string | { name: string })[] };
    };
    const owner = [
        ...(auth.roles ?? []),
        ...(auth.user?.roles ?? []).map((r) =>
            typeof r === 'string' ? r : r.name,
        ),
    ].includes('owner');
    const canResearch = [
        ...(auth.roles ?? []),
        ...(auth.user?.roles ?? []).map((r) =>
            typeof r === 'string' ? r : r.name,
        ),
    ].some((role) => ['researcher', 'owner'].includes(role));
    return (
        <>
            <Head title="Agent outputs" />
            <PageHeading
                eyebrow="Evidence, documents & strategies"
                title="Agent outputs"
            />
            <Panel
                title="Scheduled-run receipts"
                note="Observed native scheduler records"
                className="mb-5"
            >
                <p className="mb-4 text-xs leading-6 text-muted-foreground">
                    Run status and report availability are separate. A failed or
                    interrupted receipt never becomes a completed research
                    document.
                </p>
                <DataTable
                    rows={runs.map((run) => ({
                        title: run.title,
                        symbol: run.symbol,
                        created_at: run.created_at,
                        status: run.status,
                        reason: run.reason,
                        report:
                            run.has_report === true
                                ? 'Available'
                                : 'No report artifact',
                    }))}
                    empty="No scheduled-run receipts available"
                />
            </Panel>
            <div className="grid gap-5 xl:grid-cols-[1.35fr_1fr]">
                <Panel title="Your library" note={`${outputs.length} outputs`}>
                    {outputs.length ? (
                        <div className="space-y-2">
                            {outputs.map((output) => (
                                <button
                                    onClick={() => {
                                        setSelected(output);
                                        edit.setData({
                                            title: output.title,
                                            content: output.content,
                                            version: output.version ?? 1,
                                        });
                                    }}
                                    key={output.id}
                                    className="flex w-full items-center gap-4 rounded-lg border border-white/5 p-4 text-left hover:bg-white/5"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {output.title}
                                        </p>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {output.kind} ·{' '}
                                            {output.author?.name ?? 'Research'}
                                        </p>
                                    </div>
                                    <Status>{output.status}</Status>
                                </button>
                            ))}
                        </div>
                    ) : (
                        <Empty title="Your research library starts here">
                            Create a document or strategy proposal. Submit it
                            for review before sharing with the community.
                        </Empty>
                    )}
                </Panel>
                {canResearch && (
                    <Panel title="Create an output">
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/outputs', {
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <label className="block text-xs">
                                Title
                                <input
                                    required
                                    className="hub-input mt-2"
                                    value={form.data.title}
                                    onChange={(e) =>
                                        form.setData('title', e.target.value)
                                    }
                                />
                            </label>
                            <label className="block text-xs">
                                Type
                                <select
                                    className="hub-input mt-2"
                                    value={form.data.kind}
                                    onChange={(e) =>
                                        form.setData('kind', e.target.value)
                                    }
                                >
                                    <option value="research">Research</option>
                                    <option value="strategy">
                                        Strategy proposal
                                    </option>
                                    <option value="document">Document</option>
                                </select>
                            </label>
                            <label className="block text-xs">
                                Content
                                <textarea
                                    required
                                    rows={7}
                                    className="hub-input mt-2"
                                    value={form.data.content}
                                    onChange={(e) =>
                                        form.setData('content', e.target.value)
                                    }
                                />
                            </label>
                            <Errors errors={form.errors} />
                            <button
                                className="hub-button primary"
                                disabled={form.processing}
                            >
                                Save draft
                            </button>
                        </form>
                    </Panel>
                )}
                {selected && (
                    <Panel
                        title={selected.title}
                        note={selected.status}
                        className="xl:col-span-2"
                    >
                        <div className="mb-6 text-muted-foreground">
                            <Markdown>{selected.content}</Markdown>
                        </div>
                        {selected.user_id === auth.user?.id &&
                            ['draft', 'changes_requested'].includes(
                                selected.status,
                            ) &&
                            !selected.external_id && (
                                <form
                                    className="mb-5 space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        edit.patch(`/outputs/${selected.id}`, {
                                            onSuccess: () => setSelected(null),
                                        });
                                    }}
                                >
                                    <label className="block text-xs">
                                        Title
                                        <input
                                            required
                                            className="hub-input mt-2"
                                            value={edit.data.title}
                                            onChange={(e) =>
                                                edit.setData(
                                                    'title',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <label className="block text-xs">
                                        Revise draft
                                        <textarea
                                            required
                                            rows={7}
                                            className="hub-input mt-2"
                                            value={edit.data.content}
                                            onChange={(e) =>
                                                edit.setData(
                                                    'content',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                    <Errors errors={edit.errors} />
                                    <button
                                        disabled={edit.processing}
                                        className="hub-button"
                                    >
                                        Save revision
                                    </button>
                                </form>
                            )}
                        {selected.review_comment && (
                            <p className="mb-4 text-sm text-amber-200">
                                Review: {selected.review_comment}
                            </p>
                        )}
                        {selected.sources?.map((source) => (
                            <a
                                key={source.url}
                                className="hub-link mr-4 mb-3"
                                href={
                                    /^https?:\/\//i.test(source.url)
                                        ? source.url
                                        : undefined
                                }
                                target="_blank"
                                rel="noreferrer"
                            >
                                {source.title ?? source.url}
                            </a>
                        ))}
                        {selected.user_id === auth.user?.id &&
                            ['draft', 'changes_requested'].includes(
                                selected.status,
                            ) && (
                                <button
                                    className="hub-button"
                                    onClick={() =>
                                        router.post(
                                            `/outputs/${selected.id}/submit`,
                                            {},
                                            {
                                                onSuccess: () =>
                                                    setSelected(null),
                                            },
                                        )
                                    }
                                >
                                    Submit for owner review
                                </button>
                            )}
                        {owner && ['in_review'].includes(selected.status) && (
                            <form
                                className="mt-4 space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    review.post(
                                        `/outputs/${selected.id}/review`,
                                        { onSuccess: () => setSelected(null) },
                                    );
                                }}
                            >
                                <Item variant="outline" size="sm">
                                    <ItemContent>
                                        <ItemTitle>
                                            Confirm publication review
                                        </ItemTitle>
                                        <p className="mb-3 text-xs text-muted-foreground">
                                            Publish shares this output with
                                            approved members. Request changes
                                            keeps it in the review workflow.
                                        </p>
                                        <label className="block text-xs">
                                            Review feedback
                                            <textarea
                                                className="hub-input mt-2"
                                                value={review.data.comment}
                                                onChange={(e) =>
                                                    review.setData(
                                                        'comment',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </label>
                                        <select
                                            aria-label="Review decision"
                                            className="hub-input"
                                            value={review.data.decision}
                                            onChange={(e) =>
                                                review.setData(
                                                    'decision',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="publish">
                                                Publish
                                            </option>
                                            <option value="changes_requested">
                                                Request changes
                                            </option>
                                        </select>
                                        <Errors errors={review.errors} />
                                    </ItemContent>
                                    <ItemActions>
                                        <button
                                            className="hub-button primary"
                                            disabled={review.processing}
                                        >
                                            Confirm review
                                        </button>
                                    </ItemActions>
                                </Item>
                            </form>
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}
