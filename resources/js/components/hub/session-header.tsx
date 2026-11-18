import { router, useForm } from '@inertiajs/react';
import { PanelRight, Pencil, Pin, PinOff, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Errors } from '@/components/hub/shared';
import { ConfirmDelete } from '@/components/kit/confirm-delete';
import { StatusMark } from '@/components/kit/micro';
import type { Session } from '@/types/hub';
export default function SessionHeader({
    session,
    status,
    rawStatus,
    onContext,
    actions,
}: {
    session: Session | null;
    status?: string;
    rawStatus?: string;
    onContext?: () => void;
    actions?: ReactNode;
}) {
    const [renaming, setRenaming] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const rename = useForm({ title: session?.title ?? '' });
    const pin = useForm({ pinned: !session?.pinned });
    useEffect(() => {
        if (renaming) input.current?.focus();
    }, [renaming]);
    return (
        <header className="group/header shrink-0 border-b border-[var(--kit-line)] px-4 py-2.5 md:px-5">
            <div className="flex min-h-9 min-w-0 items-center gap-2">
                {renaming && session ? (
                    <form
                        className="flex min-w-0 flex-1 gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            rename.patch(`/research/sessions/${session.id}`, {
                                preserveScroll: true,
                                onSuccess: () => setRenaming(false),
                            });
                        }}
                    >
                        <input
                            ref={input}
                            aria-label="Session title"
                            required
                            maxLength={160}
                            className="hub-input min-w-0 flex-1 text-xs"
                            value={rename.data.title}
                            onChange={(e) =>
                                rename.setData('title', e.target.value)
                            }
                        />
                        <button
                            className="hub-button"
                            disabled={
                                rename.processing || !rename.data.title.trim()
                            }
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            aria-label="Cancel rename"
                            onClick={() => setRenaming(false)}
                        >
                            <X className="size-4" />
                        </button>
                    </form>
                ) : (
                    <>
                        <h2 className="min-w-0 truncate text-sm font-medium">
                            {session?.title ?? 'New session'}
                        </h2>
                        {session && (
                            <>
                                <button
                                    type="button"
                                    aria-label="Rename session"
                                    className="kit-tool !h-7 !min-w-7 !px-1 opacity-0 group-hover/header:opacity-100 focus-visible:opacity-100 max-md:opacity-100"
                                    onClick={() => {
                                        rename.setData('title', session.title);
                                        setRenaming(true);
                                    }}
                                >
                                    <Pencil className="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    aria-label={
                                        session.pinned
                                            ? 'Unpin session'
                                            : 'Pin session'
                                    }
                                    aria-pressed={!!session.pinned}
                                    className="kit-tool !h-7 !min-w-7 !px-1 opacity-0 group-hover/header:opacity-100 focus-visible:opacity-100 max-md:opacity-100"
                                    disabled={pin.processing}
                                    onClick={() => {
                                        pin.transform(() => ({
                                            pinned: !session.pinned,
                                        }));
                                        pin.patch(
                                            `/research/sessions/${session.id}`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    {session.pinned ? (
                                        <PinOff className="size-3.5" />
                                    ) : (
                                        <Pin className="size-3.5" />
                                    )}
                                </button>
                                {!session.runs?.length && (
                                    <ConfirmDelete
                                        label="Delete empty session"
                                        className="opacity-0 group-hover/header:opacity-100 focus-visible:opacity-100 max-md:opacity-100"
                                        onConfirm={() =>
                                            router.delete(
                                                `/research/sessions/${session.id}`,
                                            )
                                        }
                                    />
                                )}
                            </>
                        )}
                        <span className="flex-1" />
                    </>
                )}
                {status && !renaming && (
                    <span className="kit-pill">
                        <StatusMark status={rawStatus ?? status} size={12} />
                        {status}
                    </span>
                )}
                {!renaming && actions}
                {onContext && (
                    <button
                        type="button"
                        className="kit-tool max-xl:!hidden"
                        aria-label="Toggle session context"
                        title="Session context"
                        onClick={onContext}
                    >
                        <PanelRight className="size-4" />
                    </button>
                )}
            </div>
            <Errors errors={{ ...rename.errors, ...pin.errors }} />
        </header>
    );
}
