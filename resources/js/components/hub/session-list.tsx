import { router } from '@inertiajs/react';
import { Pin } from 'lucide-react';
import { Fragment } from 'react';
import type { Session } from '@/types/hub';
export default function SessionList({
    sessions,
    selected,
    query,
    running,
    onSelect,
}: {
    sessions: Session[];
    selected?: string | number;
    query: string;
    running: boolean;
    onSelect: () => void;
}) {
    const filtered = sessions.filter((session) =>
        session.title.toLowerCase().includes(query.toLowerCase()),
    );
    const group = (session: Session) =>
        session.pinned
            ? 'Pinned'
            : new Date(session.updated_at).toLocaleDateString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              });
    return (
        <div className="space-y-1">
            {filtered.map((session, index) => (
                <Fragment key={session.id}>
                    {(index === 0 ||
                        group(filtered[index - 1]) !== group(session)) && (
                        <p className="hub-eyebrow px-2 pt-5 pb-2">
                            {group(session)}
                        </p>
                    )}
                    <button
                        className={`w-full rounded-md border p-3 text-left ${selected === session.id ? 'border-white/10 bg-white/5' : 'border-transparent hover:bg-white/[.03]'}`}
                        onClick={() => {
                            router.get(
                                '/research',
                                { session: session.id },
                                { preserveState: true },
                            );
                            onSelect();
                        }}
                    >
                        <span className="flex items-center gap-2">
                            <span className="min-w-0 flex-1 truncate text-xs">
                                {session.title}
                            </span>
                            {session.pinned && (
                                <Pin
                                    aria-label="Pinned"
                                    className="size-3 text-muted-foreground"
                                />
                            )}
                            {running && selected === session.id && (
                                <span
                                    className="size-1.5 rounded-full bg-emerald-300"
                                    aria-label="Active run"
                                />
                            )}
                        </span>
                        <span className="mt-2 block text-[10px] text-muted-foreground">
                            {session.runs_count ?? 0} runs
                        </span>
                    </button>
                </Fragment>
            ))}
            {!filtered.length && (
                <p className="px-2 text-xs leading-6 text-muted-foreground">
                    {query
                        ? 'No sessions match your search.'
                        : 'Your private sessions will appear here.'}
                </p>
            )}
        </div>
    );
}
