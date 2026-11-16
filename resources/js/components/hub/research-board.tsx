import { useForm } from '@inertiajs/react';
import { useIsMobile } from '@/hooks/use-mobile';
import { useEffect, useRef, useState } from 'react';
import Markdown from '@/components/hub/markdown';
import { Errors } from '@/components/hub/shared';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { BoardCard, Session } from '@/types/hub';
const columns = [
    { value: 'backlog', title: 'Backlog' },
    { value: 'researching', title: 'Researching' },
    { value: 'review', title: 'Review' },
    { value: 'done', title: 'Done' },
];
function MoveCard({
    sessionId,
    card,
}: {
    sessionId: Session['id'];
    card: BoardCard;
}) {
    const form = useForm({ status: card.status, version: card.version });
    return (
        <div>
            <select
                aria-label={`Move ${card.title}`}
                className="hub-input text-xs"
                value={card.status}
                disabled={form.processing}
                onChange={(e) => {
                    const status = e.target.value;
                    form.transform(() => ({ status, version: card.version }));
                    form.patch(
                        `/research/sessions/${sessionId}/board/${card.id}`,
                        { preserveScroll: true },
                    );
                }}
            >
                {columns.map((column) => (
                    <option key={column.value} value={column.value}>
                        {column.title}
                    </option>
                ))}
            </select>
            <Errors errors={form.errors} />
        </div>
    );
}
function CardEditor({
    sessionId,
    card,
    onClose,
}: {
    sessionId: Session['id'];
    card: BoardCard;
    onClose: () => void;
}) {
    const current = () => ({
        title: card.title,
        content: card.content ?? '',
        status: card.status,
        version: card.version,
    });
    const form = useForm(current());
    const titleInput = useRef<HTMLInputElement>(null);
    useEffect(() => {
        titleInput.current?.focus();
    }, []);
    const stale = form.data.version !== card.version;
    return (
        <section
            aria-label="Edit board card"
            className="mb-5 w-full max-w-xl min-w-0 rounded-lg border border-white/10 p-4"
        >
            <h3 className="mb-4 text-sm font-medium">Edit board card</h3>
            <form
                className="space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(
                        `/research/sessions/${sessionId}/board/${card.id}`,
                        {
                            preserveScroll: true,
                            onSuccess: () => onClose(),
                        },
                    );
                }}
            >
                <label className="block text-xs">
                    Card title
                    <input
                        ref={titleInput}
                        required
                        maxLength={160}
                        className="hub-input mt-2 text-sm"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="block text-xs">
                    Card content
                    <textarea
                        rows={7}
                        maxLength={50000}
                        className="hub-input mt-2 text-sm"
                        value={form.data.content}
                        onChange={(e) =>
                            form.setData('content', e.target.value)
                        }
                    />
                </label>
                <label className="block text-xs">
                    Status
                    <select
                        className="hub-input mt-2 text-xs"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {columns.map((column) => (
                            <option key={column.value} value={column.value}>
                                {column.title}
                            </option>
                        ))}
                    </select>
                </label>
                {stale && (
                    <div
                        role="status"
                        className="text-xs leading-6 text-amber-200"
                    >
                        A newer card version is available. Your draft is
                        preserved.
                        <button
                            type="button"
                            className="hub-button mt-2"
                            onClick={() => form.setData(current())}
                        >
                            Load latest card
                        </button>
                    </div>
                )}
                <Errors errors={form.errors} />
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        className="hub-button primary"
                        disabled={
                            form.processing || stale || !form.data.title.trim()
                        }
                    >
                        Save card
                    </button>
                    <button
                        type="button"
                        className="hub-button"
                        onClick={() => onClose()}
                    >
                        Cancel
                    </button>
                    <span className="text-[10px] text-muted-foreground">
                        Version {form.data.version}
                    </span>
                </div>
            </form>
        </section>
    );
}
function KanbanCard({
    sessionId,
    card,
    editing,
    onEdit,
}: {
    sessionId: Session['id'];
    card: BoardCard;
    editing: boolean;
    onEdit: () => void;
}) {
    return (
        <article className="min-w-0 rounded-lg border border-white/10 bg-white/[.035] p-4">
            <h4 className="mb-3 text-sm font-medium break-words">
                {card.title}
            </h4>
            {card.content ? (
                <div className="mb-4 max-h-52 overflow-y-auto text-sm text-muted-foreground">
                    <Markdown>{card.content}</Markdown>
                </div>
            ) : (
                <p className="mb-4 text-xs text-muted-foreground">
                    No card content yet.
                </p>
            )}
            {editing ? (
                <p className="text-xs text-emerald-200">Editing above</p>
            ) : (
                <>
                    <MoveCard sessionId={sessionId} card={card} />
                    <button
                        type="button"
                        className="hub-button mt-3"
                        onClick={onEdit}
                    >
                        Edit card
                    </button>
                </>
            )}
        </article>
    );
}
export default function ResearchBoard({ session }: { session: Session }) {
    const mobile = useIsMobile();
    const create = useForm({ title: '', content: '', status: 'backlog' });
    const cards = session.board ?? [];
    const [editingCardId, setEditingCardId] = useState<string | null>(null);
    const editingCard = cards.find((card) => card.id === editingCardId);
    const createForm = () => (
        <form
            className="space-y-2"
            onSubmit={(e) => {
                e.preventDefault();
                create.post(`/research/sessions/${session.id}/board`, {
                    preserveScroll: true,
                    onSuccess: () => create.reset(),
                });
            }}
        >
            <input
                aria-label="New board card title"
                required
                maxLength={160}
                placeholder="New thesis or task…"
                className="hub-input text-xs"
                value={create.data.title}
                onChange={(e) => create.setData('title', e.target.value)}
            />
            <Errors errors={create.errors} />
            <button className="hub-button w-full" disabled={create.processing}>
                Add to backlog
            </button>
        </form>
    );
    return (
        <details open className="mb-6">
            <summary className="cursor-pointer text-xs font-medium">
                Working board
            </summary>
            <Dialog>
                <DialogTrigger asChild>
                    <button type="button" className="hub-button my-4 w-full">
                        Open board · {cards.length} cards
                    </button>
                </DialogTrigger>
                <DialogContent
                    style={{
                        inset: mobile ? 0 : '2rem',
                        margin: mobile ? 0 : 'auto',
                        width: 'auto',
                        height: mobile
                            ? 'auto'
                            : 'min(800px, calc(100dvh - 4rem))',
                        minWidth: 0,
                        maxWidth: mobile ? 'none' : '72rem',
                        transform: 'none',
                        translate: 'none',
                    }}
                    className="flex min-w-0 flex-col gap-5 overflow-hidden rounded-none bg-[#171719] p-4 text-[#eeeeef] sm:rounded-xl sm:p-6"
                >
                    <DialogHeader className="max-w-full min-w-0 shrink-0 pr-8">
                        <DialogTitle>Working board</DialogTitle>
                        <DialogDescription className="min-w-0 [overflow-wrap:anywhere] whitespace-normal">
                            {session.title} · Private session. Move cards with
                            the status controls or edit the title and content.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 max-w-full min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain">
                        <div className="mb-5 max-w-sm">{createForm()}</div>
                        {editingCard && (
                            <CardEditor
                                key={editingCard.id}
                                card={editingCard}
                                sessionId={session.id}
                                onClose={() => setEditingCardId(null)}
                            />
                        )}
                        <div className="max-w-full min-w-0 overflow-x-auto overscroll-x-contain">
                            <div className="grid min-w-[1020px] grid-cols-4 items-start gap-4 pb-4">
                                {columns.map((column) => {
                                    const items = cards.filter(
                                        (card) => card.status === column.value,
                                    );
                                    return (
                                        <section
                                            key={column.value}
                                            aria-label={`${column.title} column`}
                                            className="min-w-0 rounded-lg border border-white/10 bg-black/10 p-3"
                                        >
                                            <h3 className="mb-4 flex items-center justify-between text-sm font-medium">
                                                {column.title}
                                                <span className="text-xs text-muted-foreground">
                                                    {items.length}
                                                </span>
                                            </h3>
                                            <div className="space-y-3">
                                                {items.map((card) => (
                                                    <KanbanCard
                                                        key={card.id}
                                                        card={card}
                                                        sessionId={session.id}
                                                        editing={
                                                            editingCardId ===
                                                            card.id
                                                        }
                                                        onEdit={() =>
                                                            setEditingCardId(
                                                                card.id,
                                                            )
                                                        }
                                                    />
                                                ))}
                                                {!items.length && (
                                                    <p className="py-6 text-center text-xs text-muted-foreground">
                                                        No cards in{' '}
                                                        {column.title.toLowerCase()}
                                                        .
                                                    </p>
                                                )}
                                            </div>
                                        </section>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
            <div className="space-y-3">
                {cards.length ? (
                    cards.map((card) => (
                        <div
                            key={card.id}
                            className="rounded-md border border-white/10 bg-white/[.025] p-3"
                        >
                            <p className="mb-2 text-xs break-words">
                                {card.title}
                            </p>
                            {card.content && (
                                <p className="mb-3 line-clamp-3 text-xs leading-6 text-muted-foreground">
                                    {card.content}
                                </p>
                            )}
                            <MoveCard sessionId={session.id} card={card} />
                        </div>
                    ))
                ) : (
                    <p className="text-xs leading-6 text-muted-foreground">
                        Capture a thesis and move it with the keyboard or touch.
                    </p>
                )}
                {createForm()}
            </div>
        </details>
    );
}
