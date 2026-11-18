// Adapted from cult-ui Collab Avatar and Copy Button (MIT, https://www.cult-ui.com),
// rebuilt without `motion`. See ./SOURCES.md.
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

const palette = [
    '#60a5fa',
    '#a78bfa',
    '#f472b6',
    '#fb923c',
    '#34d399',
    '#22d3ee',
    '#facc15',
    '#f87171',
];

/** Stable member colour derived from the display name. */
export function memberColor(name: string) {
    let hash = 0;
    for (const char of name) hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    return palette[hash % palette.length];
}

export function initials(name?: string) {
    const parts = (name ?? 'Member').trim().split(/\s+/).filter(Boolean);
    return parts.length >= 2
        ? `${parts[0][0]}${parts[1][0]}`.toUpperCase()
        : (parts[0] ?? '?').slice(0, 2).toUpperCase();
}

export function CollabAvatar({
    name = 'Member',
    src,
    size = 28,
    className,
}: {
    name?: string;
    src?: string | null;
    size?: number;
    className?: string;
}) {
    const color = memberColor(name);
    const [failed, setFailed] = useState<string | null>(null);
    if (src && failed !== src)
        return (
            <img
                src={src}
                alt={name}
                title={name}
                width={size}
                height={size}
                loading="lazy"
                onError={() => setFailed(src)}
                className={cn('kit-collab-avatar object-cover', className)}
                style={{ width: size, height: size }}
            />
        );
    return (
        <span
            role="img"
            aria-label={name}
            title={name}
            className={cn('kit-collab-avatar', className)}
            style={{
                width: size,
                height: size,
                fontSize: size * 0.38,
                color,
                background: `linear-gradient(180deg, rgb(255 255 255 / 14%) 0%, transparent 48%), linear-gradient(135deg, ${color}18, ${color}3a)`,
                border: `1px solid ${color}55`,
                boxShadow: `0 0 0 1px ${color}22, inset 0 1px 0 rgb(255 255 255 / 18%)`,
            }}
        >
            <span aria-hidden>{initials(name)}</span>
        </span>
    );
}

type Person = { name: string; avatar?: string | null };

export function CollabStack({
    people,
    max = 4,
}: {
    people: Person[];
    max?: number;
}) {
    const unique = [
        ...new Map(people.map((person) => [person.name, person])).values(),
    ];
    return (
        <span className="flex items-center">
            {unique.slice(0, max).map(({ name, avatar }, index) => (
                <CollabAvatar
                    key={name}
                    name={name}
                    src={avatar}
                    size={22}
                    className={
                        index
                            ? '-ml-1.5 ring-2 ring-[var(--background)]'
                            : 'ring-2 ring-[var(--background)]'
                    }
                />
            ))}
            {unique.length > max && (
                <span className="ml-1 text-[11px] text-[var(--kit-faint)]">
                    +{unique.length - max}
                </span>
            )}
        </span>
    );
}

export function CopyButton({
    text,
    label = 'Copy',
    className,
}: {
    text: string;
    label?: string;
    className?: string;
}) {
    const [copied, setCopied] = useState(false);
    useEffect(() => {
        if (!copied) return;
        const id = setTimeout(() => setCopied(false), 1600);
        return () => clearTimeout(id);
    }, [copied]);
    return (
        <button
            type="button"
            className={cn('kit-tool kit-copy !h-7 !min-w-7 !px-1.5', className)}
            aria-label={copied ? 'Copied' : label}
            title={copied ? 'Copied' : label}
            data-copied={copied ? '' : undefined}
            onClick={async () => {
                try {
                    await navigator.clipboard.writeText(text);
                    setCopied(true);
                } catch {
                    /* clipboard unavailable */
                }
            }}
        >
            <span className="kit-copy-icons">
                <Copy className="size-3.5" />
                <Check className="size-3.5 text-[var(--chart-4)]" />
            </span>
        </button>
    );
}
