import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Errors } from '@/components/hub/shared';
import { ConfirmDelete } from '@/components/kit/confirm-delete';
import type { BoardCard, Session } from '@/types/hub';
export const boardColumns = [
    { value: 'backlog', title: 'Backlog' },
    { value: 'researching', title: 'Researching' },
    { value: 'review', title: 'Review' },
    { value: 'done', title: 'Done' },
] as const;
export function CardEditor({
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
        <section aria-label="Edit board card" className="min-w-0 p-5">
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
                        {boardColumns.map((column) => (
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
                    <span className="flex-1 text-[10px] text-muted-foreground">
                        Version {form.data.version}
                    </span>
                    <ConfirmDelete
                        label="Delete card"
                        disabled={form.processing || stale}
                        onConfirm={() =>
                            router.delete(
                                `/research/sessions/${sessionId}/board/${card.id}`,
                                {
                                    data: { version: card.version },
                                    preserveScroll: true,
                                    onSuccess: () => onClose(),
                                    onError: (errors) => form.setError(errors),
                                },
                            )
                        }
                    />
                </div>
            </form>
        </section>
    );
}
