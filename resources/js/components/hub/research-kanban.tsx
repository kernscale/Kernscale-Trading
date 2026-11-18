// Kanban adapted from cult-ui Kanban Board (MIT, https://www.cult-ui.com/docs/components/kanban-board):
// native drag and drop, column status glyphs, drop indicator and card enter motion,
// rebuilt without `motion`/`next/image` and bound to versioned research board cards.
import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type KeyboardEvent } from 'react';
import { CardEditor, boardColumns } from '@/components/hub/research-board';
import { Errors } from '@/components/hub/shared';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { BoardCard, Session } from '@/types/hub';

type Status = (typeof boardColumns)[number]['value'];

function ColumnGlyph({ status }: { status: Status }) {
    const stroke = {
        backlog: 'var(--kit-faint)',
        researching: '#f59e0b',
        review: 'var(--chart-2)',
        done: '#10b981',
    }[status];
    return (
        <svg
            aria-hidden
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke={stroke}
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle
                cx="12"
                cy="12"
                r="10"
                strokeDasharray={status === 'backlog' ? '3 3' : undefined}
            />
            {status === 'researching' && <polyline points="12 6 12 12 16 14" />}
            {status === 'review' && <circle cx="12" cy="12" r="3" />}
            {status === 'done' && <path d="m9 12 2 2 4-4" />}
        </svg>
    );
}

function AddCard({ sessionId }: { sessionId: Session['id'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ title: '', status: 'backlog' });
    if (!open)
        return (
            <button
                type="button"
                className="kit-row !py-2 text-[13px] text-[var(--kit-faint)] hover:text-foreground"
                onClick={() => setOpen(true)}
            >
                <Plus className="size-3.5" />
                Add card
            </button>
        );
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/research/sessions/${sessionId}/board`, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => form.reset('title'),
                });
            }}
        >
            <input
                autoFocus
                aria-label="New board card title"
                required
                maxLength={160}
                placeholder="Title, Enter to add"
                className="hub-input !rounded-lg !py-2 !text-[13px]"
                value={form.data.title}
                onChange={(e) => form.setData('title', e.target.value)}
                onBlur={() => !form.data.title && setOpen(false)}
                onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
            />
            <Errors errors={form.errors} />
        </form>
    );
}

export default function ResearchKanban({
    session,
    onResearch,
}: {
    session: Session;
    onResearch?: (card: BoardCard) => void;
}) {
    const cards = session.board ?? [];
    const [moved, setMoved] = useState<Record<string, Status>>({});
    const [dragId, setDragId] = useState<string | null>(null);
    const [over, setOver] = useState<Status | null>(null);
    const [editingId, setEditingId] = useState<string | null>(null);
    const statusOf = (card: BoardCard) =>
        moved[card.id] ?? (card.status as Status);
    const editing = cards.find((card) => card.id === editingId);

    const move = (card: BoardCard, status: Status) => {
        if (statusOf(card) === status) return;
        setMoved((current) => ({ ...current, [card.id]: status }));
        const settle = () =>
            setMoved((current) => {
                const next = { ...current };
                delete next[card.id];
                return next;
            });
        router.patch(
            `/research/sessions/${session.id}/board/${card.id}`,
            { status, version: card.version },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['activeSession'],
                onSuccess: () => {
                    if (status === 'researching') onResearch?.(card);
                },
                onFinish: settle,
            },
        );
    };
    const onKey = (event: KeyboardEvent, card: BoardCard) => {
        const index = boardColumns.findIndex((c) => c.value === statusOf(card));
        const step =
            event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
        if (step && boardColumns[index + step]) {
            event.preventDefault();
            move(card, boardColumns[index + step].value);
        }
    };

    return (
        <div className="research-kanban">
            {boardColumns.map((column) => {
                const items = cards.filter(
                    (card) => statusOf(card) === column.value,
                );
                return (
                    <section
                        key={column.value}
                        aria-label={`${column.title} column`}
                        className="kanban-column"
                        data-over={over === column.value ? '' : undefined}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setOver(column.value);
                        }}
                        onDragLeave={(e) => {
                            if (
                                !e.currentTarget.contains(
                                    e.relatedTarget as Node,
                                )
                            )
                                setOver(null);
                        }}
                        onDrop={(e) => {
                            e.preventDefault();
                            const card = cards.find((c) => c.id === dragId);
                            if (card) move(card, column.value);
                            setOver(null);
                            setDragId(null);
                        }}
                    >
                        <h3 className="flex items-center gap-2 px-1.5 pb-2 text-[13px] font-medium">
                            <ColumnGlyph status={column.value} />
                            {column.title}
                            <span className="text-[var(--kit-faint)] tabular-nums">
                                {items.length}
                            </span>
                        </h3>
                        <div className="flex flex-col gap-1.5">
                            {items.map((card) => (
                                <button
                                    key={card.id}
                                    type="button"
                                    draggable
                                    aria-label={`${card.title}, ${column.title}. Arrow keys move between columns.`}
                                    className={cn(
                                        'kanban-card',
                                        dragId === card.id && 'opacity-40',
                                        column.value === 'done' &&
                                            'text-[var(--kit-soft)]',
                                    )}
                                    onDragStart={(e) => {
                                        e.dataTransfer.effectAllowed = 'move';
                                        setDragId(card.id);
                                    }}
                                    onDragEnd={() => {
                                        setDragId(null);
                                        setOver(null);
                                    }}
                                    onKeyDown={(e) => onKey(e, card)}
                                    onClick={() => setEditingId(card.id)}
                                >
                                    <span
                                        className={cn(
                                            'block text-[13.5px] leading-snug font-medium break-words',
                                            column.value === 'done' &&
                                                'line-through decoration-[var(--kit-faint)]',
                                        )}
                                    >
                                        {card.title}
                                    </span>
                                    {card.content && (
                                        <span className="mt-1 line-clamp-2 block text-[12px] leading-5 text-[var(--kit-faint)]">
                                            {card.content}
                                        </span>
                                    )}
                                </button>
                            ))}
                            {column.value === 'researching' &&
                                !items.length &&
                                onResearch && (
                                    <p className="px-1.5 text-[12px] leading-5 text-[var(--kit-faint)]">
                                        Drop a card here to start researching
                                        it
                                    </p>
                                )}
                            {over === column.value && dragId && (
                                <span aria-hidden className="kanban-drop" />
                            )}
                            {column.value === 'backlog' && (
                                <AddCard sessionId={session.id} />
                            )}
                        </div>
                    </section>
                );
            })}
            <Dialog
                open={!!editing}
                onOpenChange={(open) => !open && setEditingId(null)}
            >
                <DialogContent className="max-w-xl border-[var(--kit-line)] bg-[var(--background)] p-0">
                    <DialogTitle className="sr-only">
                        Edit board card
                    </DialogTitle>
                    {editing && (
                        <CardEditor
                            key={editing.id}
                            card={editing}
                            sessionId={session.id}
                            onClose={() => setEditingId(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
