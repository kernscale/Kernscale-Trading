import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, SquarePen } from 'lucide-react';
import { useState } from 'react';
import { Errors } from '@/components/hub/shared';
import { Composer } from '@/components/kit/composer';
import { CollabAvatar, CollabStack } from '@/components/kit/collab';

type Member = { name: string; avatar?: string | null };

type Topic = {
    id: number;
    title: string;
    body: string;
    created_at: string;
    user: Member;
    comments: { id: number; body: string; user?: Member }[];
};

const when = (value: string) => {
    const time = Date.parse(value);
    return Number.isNaN(time)
        ? ''
        : new Date(time).toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          });
};

function Post({
    name,
    avatar,
    body,
    meta,
    lead = false,
}: {
    name?: string;
    avatar?: string | null;
    body: string;
    meta?: string;
    lead?: boolean;
}) {
    return (
        <div className="flex gap-3">
            <CollabAvatar name={name ?? 'Member'} src={avatar} size={30} />
            <div className="min-w-0 flex-1">
                <p className="text-[13px]">
                    <span className="font-medium">{name ?? 'Member'}</span>
                    {meta && (
                        <span className="ml-2 text-[var(--kit-faint)]">
                            {meta}
                        </span>
                    )}
                </p>
                <p
                    className={`mt-1 whitespace-pre-wrap ${lead ? 'text-[15px] leading-7' : 'text-[14px] leading-6 text-[var(--kit-soft)]'}`}
                >
                    {body}
                </p>
            </div>
        </div>
    );
}

function Discussion({ topic, onBack }: { topic: Topic; onBack: () => void }) {
    const form = useForm({ body: '' });
    return (
        <article className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-2 border-b border-[var(--kit-line)] px-4 py-3 md:px-6">
                <button
                    type="button"
                    className="kit-tool lg:!hidden"
                    aria-label="Back to discussions"
                    onClick={onBack}
                >
                    <ArrowLeft className="size-4" />
                </button>
                <h2 className="min-w-0 flex-1 truncate text-[15px] font-medium">
                    {topic.title}
                </h2>
                <CollabStack
                    people={[topic.user, ...topic.comments.map((c) => c.user)]
                        .filter((person): person is Member => !!person)}
                />
            </header>
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-6 md:px-6">
                <div className="kit-thread space-y-7">
                    <Post
                        lead
                        name={topic.user?.name}
                        avatar={topic.user?.avatar}
                        body={topic.body}
                        meta={when(topic.created_at)}
                    />
                    {topic.comments.length > 0 && (
                        <div className="space-y-6 border-l border-[var(--kit-line)] pl-5 md:ml-[15px]">
                            {topic.comments.map((comment) => (
                                <Post
                                    key={comment.id}
                                    name={comment.user?.name}
                                    avatar={comment.user?.avatar}
                                    body={comment.body}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
            <div className="px-3 pb-3 md:px-6 md:pb-5">
                <div className="kit-thread">
                    <Composer
                        label={`Reply to ${topic.title}`}
                        sendLabel="Reply"
                        rows={1}
                        value={form.data.body}
                        onChange={(value) => form.setData('body', value)}
                        processing={form.processing}
                        placeholder="Reply"
                        onSubmit={() =>
                            form.post(`/community/${topic.id}/comments`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            })
                        }
                    />
                    <Errors errors={form.errors} />
                </div>
            </div>
        </article>
    );
}

function NewDiscussion({
    onDone,
    initialTitle = '',
}: {
    onDone: () => void;
    initialTitle?: string;
}) {
    const form = useForm({ title: initialTitle, body: '' });
    return (
        <div className="flex flex-1 flex-col justify-center px-4 py-10 md:px-8">
            <div className="kit-thread">
                <h2 className="mb-6 text-[26px] leading-tight font-medium tracking-tight">
                    New discussion
                </h2>
                <input
                    required
                    aria-label="Title"
                    placeholder="Title"
                    className="mb-3 w-full bg-transparent text-lg font-medium outline-none placeholder:text-[var(--kit-faint)]"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                <Composer
                    label="Your perspective"
                    sendLabel="Post discussion"
                    rows={5}
                    submitOnEnter={false}
                    value={form.data.body}
                    onChange={(value) => form.setData('body', value)}
                    processing={form.processing || !form.data.title.trim()}
                    placeholder="Your perspective"
                    onSubmit={() =>
                        form.post('/community', {
                            onSuccess: () => {
                                form.reset();
                                onDone();
                            },
                        })
                    }
                />
                <Errors errors={form.errors} />
            </div>
        </div>
    );
}

export default function Community({ topics = [] }: { topics?: Topic[] }) {
    const about =
        typeof window === 'undefined'
            ? ''
            : (new URLSearchParams(window.location.search).get('about') ?? '');
    const [selectedId, setSelectedId] = useState<number | null>(
        topics[0]?.id ?? null,
    );
    const [composing, setComposing] = useState(!topics.length || !!about);
    const [mobileDetail, setMobileDetail] = useState(!!about);
    const selected =
        topics.find((topic) => topic.id === selectedId) ?? topics[0] ?? null;
    const showDetail = composing || !!selected;
    return (
        <>
            <Head title="Community" />
            <div className="grid h-[calc(100dvh-9.5rem)] min-h-[32rem] overflow-hidden rounded-2xl border border-[var(--kit-line)] lg:grid-cols-[300px_minmax(0,1fr)]">
                <aside
                    className={`min-h-0 overflow-y-auto border-[var(--kit-line)] bg-[var(--kit-surface)] p-2 lg:border-r ${mobileDetail ? 'max-lg:hidden' : ''}`}
                >
                    <div className="mb-1 flex items-center justify-end gap-2 px-1">
                        <button
                            type="button"
                            className="kit-tool"
                            aria-label="Start a discussion"
                            title="Start a discussion"
                            onClick={() => {
                                setComposing(true);
                                setMobileDetail(true);
                            }}
                        >
                            <SquarePen className="size-4" />
                        </button>
                    </div>
                    {topics.length ? (
                        <ul className="space-y-0.5">
                            {topics.map((topic) => (
                                <li key={topic.id}>
                                    <button
                                        type="button"
                                        className="kit-row !items-start"
                                        aria-current={
                                            !composing &&
                                            topic.id === selected?.id
                                        }
                                        onClick={() => {
                                            setSelectedId(topic.id);
                                            setComposing(false);
                                            setMobileDetail(true);
                                        }}
                                    >
                                        <CollabAvatar
                                            name={topic.user?.name ?? 'Member'}
                                            src={topic.user?.avatar}
                                            size={28}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13.5px] font-medium">
                                                {topic.title}
                                            </span>
                                            <span className="mt-0.5 line-clamp-1 text-[12px] text-[var(--kit-faint)]">
                                                {topic.user?.name ?? 'Member'} ·{' '}
                                                {topic.comments.length}{' '}
                                                {topic.comments.length === 1
                                                    ? 'reply'
                                                    : 'replies'}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="px-3 py-2 text-[13px] leading-6 text-[var(--kit-faint)]">
                            No discussions yet. Start the first one.
                        </p>
                    )}
                </aside>
                <section
                    className={`flex min-h-0 flex-col ${mobileDetail || !topics.length ? '' : 'max-lg:hidden'}`}
                >
                    {composing || !selected ? (
                        <>
                            {topics.length > 0 && (
                                <div className="px-4 pt-3 lg:hidden">
                                    <button
                                        type="button"
                                        className="kit-tool"
                                        aria-label="Back to discussions"
                                        onClick={() => {
                                            setMobileDetail(false);
                                            setComposing(false);
                                        }}
                                    >
                                        <ArrowLeft className="size-4" />
                                    </button>
                                </div>
                            )}
                            <NewDiscussion
                                initialTitle={about ? `On: ${about}` : ''}
                                onDone={() => {
                                    setComposing(false);
                                    setSelectedId(null);
                                }}
                            />
                        </>
                    ) : (
                        showDetail && (
                            <Discussion
                                key={selected.id}
                                topic={selected}
                                onBack={() => setMobileDetail(false)}
                            />
                        )
                    )}
                </section>
            </div>
        </>
    );
}
