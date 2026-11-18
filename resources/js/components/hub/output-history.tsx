import { History } from 'lucide-react';
import { useEffect, useState } from 'react';
import { StatusMark } from '@/components/kit/micro';
import { cn } from '@/lib/utils';
import type { Output } from '@/types/hub';

export type Revision = {
    version: number;
    snapshot: {
        title: string;
        content: string;
        sources?: { url: string; title?: string }[];
        status: string;
    };
    created_at: string;
};

/** Author-only list of saved earlier versions; picking one previews its snapshot. */
export default function OutputHistory({
    output,
    viewing,
    onView,
}: {
    output: Output;
    viewing: Revision | null;
    onView: (revision: Revision | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [revisions, setRevisions] = useState<Revision[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setRevisions(null);
        setFailed(false);
        setOpen(false);
    }, [output.id, output.version]);

    useEffect(() => {
        if (!open || revisions) return;
        const controller = new AbortController();
        fetch(`/outputs/${output.id}/revisions`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) throw new Error();
                return response.json();
            })
            .then((data) => setRevisions(data.revisions ?? []))
            .catch((error) => error.name !== 'AbortError' && setFailed(true));
        return () => controller.abort();
    }, [open, revisions, output.id]);

    return (
        <div className="relative">
            <button
                type="button"
                className="kit-pill hover:text-foreground"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
            >
                <History className="size-3" />v
                {viewing?.version ?? output.version ?? 1}
            </button>
            {open && (
                <div className="absolute top-full left-0 z-20 mt-1.5 w-64 rounded-xl border border-[var(--kit-line-strong)] bg-[var(--background)] p-1.5 shadow-xl">
                    <button
                        type="button"
                        className={cn(
                            'kit-row !py-1.5 text-[13px]',
                            !viewing && 'bg-[var(--kit-hover)]',
                        )}
                        onClick={() => {
                            onView(null);
                            setOpen(false);
                        }}
                    >
                        <StatusMark status={output.status} size={12} />
                        <span className="flex-1">
                            v{output.version ?? 1} · current
                        </span>
                    </button>
                    {revisions === null && !failed && (
                        <p className="px-2.5 py-2 text-[12px] text-[var(--kit-faint)]">
                            Loading…
                        </p>
                    )}
                    {failed && (
                        <p className="px-2.5 py-2 text-[12px] text-[var(--kit-faint)]">
                            History unavailable
                        </p>
                    )}
                    {revisions?.length === 0 && (
                        <p className="px-2.5 py-2 text-[12px] text-[var(--kit-faint)]">
                            No earlier versions saved
                        </p>
                    )}
                    {revisions?.map((revision) => (
                        <button
                            key={revision.version}
                            type="button"
                            className={cn(
                                'kit-row !py-1.5 text-[13px]',
                                viewing?.version === revision.version &&
                                    'bg-[var(--kit-hover)]',
                            )}
                            onClick={() => {
                                onView(revision);
                                setOpen(false);
                            }}
                        >
                            <StatusMark
                                status={revision.snapshot.status}
                                size={12}
                            />
                            <span className="flex-1">v{revision.version}</span>
                            <span className="text-[11.5px] text-[var(--kit-faint)]">
                                {new Date(
                                    revision.created_at.replace(' ', 'T'),
                                ).toLocaleDateString(undefined, {
                                    month: 'short',
                                    day: 'numeric',
                                })}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
