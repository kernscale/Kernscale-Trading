import { Head, useForm } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { Empty, Errors, PageHeading, Panel } from '@/components/hub/shared';
type Topic = {
    id: number;
    title: string;
    body: string;
    created_at: string;
    user: { name: string };
    comments: { id: number; body: string; user?: { name: string } }[];
};
function Discussion({ topic }: { topic: Topic }) {
    const form = useForm({ body: '' });
    return (
        <Panel title={topic.title} note={topic.user?.name}>
            <p className="text-sm leading-7 whitespace-pre-wrap text-muted-foreground">
                {topic.body}
            </p>
            <div className="my-5 space-y-3 border-t border-white/10 pt-4">
                {topic.comments.map((comment) => (
                    <div
                        key={comment.id}
                        className="rounded-md bg-white/[.025] p-3"
                    >
                        <p className="mb-1 text-xs text-muted-foreground">
                            {comment.user?.name ?? 'Member'}
                        </p>
                        <p className="text-sm whitespace-pre-wrap">
                            {comment.body}
                        </p>
                    </div>
                ))}
            </div>
            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/community/${topic.id}/comments`, {
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <input
                    aria-label={`Reply to ${topic.title}`}
                    required
                    className="hub-input min-w-40 flex-1"
                    placeholder="Add to the discussion…"
                    value={form.data.body}
                    onChange={(e) => form.setData('body', e.target.value)}
                />
                <button className="hub-button" disabled={form.processing}>
                    Reply
                </button>
                <Errors errors={form.errors} />
            </form>
        </Panel>
    );
}
export default function Community({ topics = [] }: { topics?: Topic[] }) {
    const form = useForm({ title: '', body: '' });
    return (
        <>
            <Head title="Community" />
            <PageHeading eyebrow="Shared perspective" title="Community" />
            <div className="grid items-start gap-5 xl:grid-cols-[1.5fr_1fr]">
                <div className="space-y-5">
                    {topics.length ? (
                        topics.map((topic) => (
                            <Discussion key={topic.id} topic={topic} />
                        ))
                    ) : (
                        <Panel>
                            <Empty title="A place for considered discussion">
                                Share a question, discuss published research or
                                compare perspectives with other members.
                            </Empty>
                        </Panel>
                    )}
                </div>
                <Panel title="Start a discussion">
                    <MessageSquare className="mb-5 size-6 text-emerald-200" />
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/community', {
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
                            Your perspective
                            <textarea
                                required
                                rows={6}
                                className="hub-input mt-2"
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                            />
                        </label>
                        <Errors errors={form.errors} />
                        <button
                            className="hub-button primary"
                            disabled={form.processing}
                        >
                            Post discussion
                        </button>
                    </form>
                </Panel>
            </div>
        </>
    );
}
