import { useForm } from '@inertiajs/react';
import { FlaskConical, Pencil, Pin, PinOff, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Errors, Status } from '@/components/hub/shared';
import type { Session } from '@/types/hub';
export default function SessionHeader({
    session,
    status,
}: {
    session: Session | null;
    status?: string;
}) {
    const [renaming, setRenaming] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const rename = useForm({ title: session?.title ?? '' });
    const pin = useForm({ pinned: !session?.pinned });
    useEffect(() => {
        if (renaming) input.current?.focus();
    }, [renaming]);
    return (
        <header className="shrink-0 border-b border-white/5 px-5 py-4">
            <div className="flex min-w-0 items-center gap-3">
                <FlaskConical className="size-4 shrink-0 text-emerald-200" />
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
                        <h2 className="min-w-0 flex-1 truncate text-xs">
                            {session?.title ?? 'A new perspective starts here'}
                        </h2>
                        {session && (
                            <>
                                <button
                                    type="button"
                                    aria-label="Rename session"
                                    className="rounded p-1 text-muted-foreground hover:text-foreground"
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
                                    className="rounded p-1 text-muted-foreground hover:text-foreground"
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
                            </>
                        )}
                    </>
                )}
                {status && !renaming && <Status>{status}</Status>}
            </div>
            <Errors errors={{ ...rename.errors, ...pin.errors }} />
        </header>
    );
}
