import { router } from '@inertiajs/react';
import { Pin } from 'lucide-react';
import { StatusMark } from '@/components/kit/micro';
import { Fragment } from 'react';
import type { Session } from '@/types/hub';
export default function SessionList({
    sessions,
    selected,
    query,
    onSelect,
}: {
    sessions: Session[];
    selected?: string | number;
    query: string;
    onSelect: () => void;
}) {
    const filtered = sessions.filter((session) =>
        session.title.toLowerCase().includes(query.toLowerCase()),
    );
    const unread = (session: Session) =>
        !!session.has_unread && selected !== session.id;
    const group = (session: Session) => (session.pinned ? 'Pinned' : 'Recent');
    return (
        <div className="space-y-0.5">
            {filtered.map((session, index) => (
                <Fragment key={session.id}>
                    {filtered.some((item) => item.pinned) &&
                        (index === 0 ||
                            group(filtered[index - 1]) !== group(session)) && (
                            <p className="kit-section-label">
                                {group(session)}
                            </p>
                        )}
                    <button
                        className="kit-row group !py-1.5"
                        aria-current={selected === session.id}
                        onClick={() => {
                            router.get(
                                '/research',
                                { session: session.id },
                                { preserveState: true },
                            );
                            onSelect();
                        }}
                    >
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="flex items-center gap-2">
                                <span
                                    className={`min-w-0 flex-1 truncate text-[13px] ${unread(session) ? 'font-medium text-foreground' : ''}`}
                                >
                                    {session.title}
                                </span>
                                {session.pinned && (
                                    <Pin
                                        aria-label="Pinned"
                                        className="size-3 text-muted-foreground"
                                    />
                                )}
                                {session.active_run ? (
                                    <span
                                        aria-label={
                                            session.active_run.status ===
                                            'waiting_for_input'
                                                ? 'Needs your input'
                                                : 'Active run'
                                        }
                                    >
                                        <StatusMark
                                            status={
                                                session.active_run.status ===
                                                'waiting_for_input'
                                                    ? 'waiting'
                                                    : 'running'
                                            }
                                            size={12}
                                        />
                                    </span>
                                ) : (
                                    unread(session) && (
                                        <span
                                            aria-label={`${session.unread_event_count} new`}
                                            className="size-2 shrink-0 rounded-full bg-primary"
                                        />
                                    )
                                )}
                            </span>
                        </span>
                    </button>
                </Fragment>
            ))}
            {!filtered.length && (
                <p className="px-3 py-2 text-xs leading-6 text-muted-foreground">
                    {query
                        ? 'No sessions match your search.'
                        : 'Your private sessions will appear here.'}
                </p>
            )}
        </div>
    );
}
