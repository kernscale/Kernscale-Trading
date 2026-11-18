// Dependency-free adaptations of React Bits micro components (Rubber Segment,
// Status Mark, Thought Line, Call Chip). Source and license: ./SOURCES.md.
import { ChevronDown, Sparkles } from 'lucide-react';
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { cn } from '@/lib/utils';

export type SegmentOption<T extends string> = {
    value: T;
    label: ReactNode;
    count?: number;
};

/** Segmented control whose thumb stretches toward the target and squashes in. */
export function Segment<T extends string>({
    options,
    value,
    onChange,
    label,
    className,
}: {
    options: SegmentOption<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
}) {
    const root = useRef<HTMLDivElement>(null);
    const [thumb, setThumb] = useState<{ x: number; w: number } | null>(null);
    const [moving, setMoving] = useState(false);
    useLayoutEffect(() => {
        const el = root.current;
        if (!el) return;
        const place = () => {
            const active = el.querySelector<HTMLElement>(
                '[aria-selected="true"]',
            );
            if (active)
                setThumb({ x: active.offsetLeft, w: active.offsetWidth });
        };
        place();
        const observer = new ResizeObserver(place);
        observer.observe(el);
        return () => observer.disconnect();
    }, [value, options.length]);
    useEffect(() => {
        setMoving(true);
        const id = setTimeout(() => setMoving(false), 220);
        return () => clearTimeout(id);
    }, [value]);
    const move = (direction: 1 | -1) => {
        const index = options.findIndex((option) => option.value === value);
        const next =
            options[(index + direction + options.length) % options.length];
        onChange(next.value);
        root.current
            ?.querySelector<HTMLElement>(`[data-value="${next.value}"]`)
            ?.focus();
    };
    return (
        <div
            ref={root}
            role="tablist"
            aria-label={label}
            className={cn('kit-segment', className)}
            data-moving={moving ? '' : undefined}
        >
            {thumb && (
                <span
                    aria-hidden
                    className="kit-segment-thumb"
                    style={{
                        width: thumb.w,
                        transform: `translateX(${thumb.x - 3}px)`,
                        marginLeft: 3,
                    }}
                />
            )}
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="tab"
                    data-value={option.value}
                    aria-selected={option.value === value}
                    tabIndex={option.value === value ? 0 : -1}
                    onClick={() => onChange(option.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowRight') move(1);
                        if (event.key === 'ArrowLeft') move(-1);
                    }}
                >
                    {option.label}
                    {option.count !== undefined && (
                        <span className="kit-count">{option.count}</span>
                    )}
                </button>
            ))}
        </div>
    );
}

export type MarkState = 'idle' | 'running' | 'done' | 'failed' | 'waiting';

const statusTone: Record<MarkState, string> = {
    idle: 'var(--kit-faint)',
    waiting: 'var(--chart-3)',
    running: 'var(--primary)',
    done: 'var(--chart-4)',
    failed: 'var(--chart-5)',
};

/** Maps backend run/output statuses onto a status glyph state. */
export function markState(status?: string | null): MarkState {
    const value = (status ?? '').toLowerCase();
    if (/(fail|error|interrupt|cancel|reject)/.test(value)) return 'failed';
    if (/(run|stream|start|progress|processing)/.test(value)) return 'running';
    if (/(queue|pend|review|wait|changes)/.test(value)) return 'waiting';
    if (
        /(complete|done|publish|success|applied|available|^active$)/.test(value)
    )
        return 'done';
    return 'idle';
}

/** Idle ring → progress arc → drawn check, with a shake on failure. */
export function StatusMark({
    status,
    label,
    size = 14,
    className,
}: {
    status?: string | null;
    label?: ReactNode;
    size?: number;
    className?: string;
}) {
    const state = markState(status);
    const tone = statusTone[state];
    const r = 6;
    const c = 2 * Math.PI * r;
    return (
        <span className={cn('kit-status', className)} data-state={state}>
            <svg width={size} height={size} viewBox="0 0 16 16" aria-hidden>
                <circle
                    cx="8"
                    cy="8"
                    r={r}
                    fill={
                        state === 'done' || state === 'failed' ? tone : 'none'
                    }
                    fillOpacity={state === 'done' || state === 'failed' ? 1 : 0}
                    stroke={tone}
                    strokeOpacity={state === 'running' ? 0.25 : 1}
                    strokeWidth="1.6"
                    strokeDasharray={state === 'idle' ? '2.4 2.2' : undefined}
                />
                {state === 'running' && (
                    <circle
                        className="ring"
                        cx="8"
                        cy="8"
                        r={r}
                        fill="none"
                        stroke={tone}
                        strokeWidth="1.8"
                        strokeLinecap="round"
                        strokeDasharray={c}
                        strokeDashoffset={c * 0.7}
                    />
                )}
                {state === 'waiting' && (
                    <path
                        d="M8 5v3.2l2 1.3"
                        fill="none"
                        stroke={tone}
                        strokeWidth="1.6"
                        strokeLinecap="round"
                    />
                )}
                {state === 'done' && (
                    <path
                        className="tick"
                        d="M5.2 8.2l1.9 1.9 3.8-4"
                        fill="none"
                        stroke="var(--background)"
                        strokeWidth="1.8"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                )}
                {state === 'failed' && (
                    <path
                        d="M6 6l4 4M10 6l-4 4"
                        stroke="var(--background)"
                        strokeWidth="1.7"
                        strokeLinecap="round"
                    />
                )}
            </svg>
            {label !== undefined && <span>{label}</span>}
        </span>
    );
}

const clock = (seconds: number) =>
    seconds < 60
        ? `${seconds.toFixed(1)}s`
        : `${Math.floor(seconds / 60)}m ${Math.floor(seconds % 60)}s`;

/** Shimmering reasoning header with a live clock; collapses its steps when settled. */
export function ThoughtLine({
    working,
    label = 'Researching',
    doneLabel = 'Worked for',
    startedAt,
    children,
    count,
}: {
    working: boolean;
    label?: string;
    doneLabel?: string;
    startedAt?: string;
    children?: ReactNode;
    count?: number;
}) {
    const [open, setOpen] = useState(working);
    const [now, setNow] = useState(() => Date.now());
    const start = startedAt ? Date.parse(startedAt) : Number.NaN;
    useEffect(() => {
        setOpen(working);
        if (!working) return;
        const id = setInterval(() => setNow(Date.now()), 100);
        return () => clearInterval(id);
    }, [working]);
    const elapsed = Number.isNaN(start)
        ? null
        : Math.max(0, (now - start) / 1000);
    const head = (
        <span className="kit-thought" data-working={working ? '' : undefined}>
            <Sparkles
                className="kit-thought-glyph size-4 text-primary"
                aria-hidden
            />
            <span className="kit-thought-label">
                {working ? label : elapsed !== null ? doneLabel : 'Activity'}
            </span>
            {working && elapsed !== null && (
                <span className="kit-thought-time">{clock(elapsed)}</span>
            )}
            {count !== undefined && (
                <span className="kit-thought-time">
                    · {count} step{count === 1 ? '' : 's'}
                </span>
            )}
            {children && (
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'size-4 opacity-60 transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                />
            )}
            <span className="sr-only" role="status">
                {working ? label : 'Finished'}
            </span>
        </span>
    );
    if (!children) return head;
    return (
        <div>
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="rounded-lg py-1 text-left"
            >
                {head}
            </button>
            <div
                className="grid transition-[grid-template-rows] duration-300 ease-out"
                style={{ gridTemplateRows: open ? '1fr' : '0fr' }}
            >
                <div className="min-h-0 overflow-hidden">{children}</div>
            </div>
        </div>
    );
}

/** Tool-call chip that fills on arrival, settles green, or shakes red. */
export function CallChip({
    icon,
    children,
    tone,
}: {
    icon?: ReactNode;
    children: ReactNode;
    tone?: 'ok' | 'error';
}) {
    return (
        <span className="kit-call" data-tone={tone}>
            {icon && (
                <span className="[&>svg]:size-3.5" aria-hidden>
                    {icon}
                </span>
            )}
            <span className="truncate">{children}</span>
        </span>
    );
}

/** Compact, honest missing-data state used in place of large empty boxes. */
export function Quiet({
    children,
    icon,
    className,
}: {
    children: ReactNode;
    icon?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('kit-quiet', className)}>
            {icon ?? <StatusMark status="idle" />}
            <span>{children}</span>
        </div>
    );
}
