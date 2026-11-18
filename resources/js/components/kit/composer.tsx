// Claude-style prompt composer with an @ mention menu. Interaction model
// reverse-engineered from AI SDK Agents "Mention menu": grouped results as you
// type, arrow keys + Enter/Tab to pick, mentions delete as one piece.
import { ArrowUp, AtSign, Square } from 'lucide-react';
import {
    useId,
    useMemo,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import { cn } from '@/lib/utils';

export type MentionItem = {
    id: string;
    label: string;
    meta?: string;
    icon?: ReactNode;
    tone?: string;
};
export type MentionGroup = { label: string; items: MentionItem[] };

const trigger = /(^|\s)@([^\s@]{0,40})$/;

export function Composer({
    id,
    label,
    value,
    onChange,
    onSubmit,
    placeholder,
    disabled,
    busy,
    onStop,
    processing,
    mentions = [],
    tools,
    hint,
    rows = 2,
    submitOnEnter = true,
    sendLabel = 'Send',
    className,
}: {
    id?: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
    placeholder?: string;
    disabled?: boolean;
    busy?: boolean;
    onStop?: () => void;
    processing?: boolean;
    mentions?: MentionGroup[];
    tools?: ReactNode;
    hint?: ReactNode;
    rows?: number;
    submitOnEnter?: boolean;
    sendLabel?: string;
    className?: string;
}) {
    const fallbackId = useId();
    const fieldId = id ?? fallbackId;
    const listId = `${fieldId}-mentions`;
    const field = useRef<HTMLTextAreaElement>(null);
    const [query, setQuery] = useState<string | null>(null);
    const [active, setActive] = useState(0);
    const hasMentions = mentions.some((group) => group.items.length);

    const groups = useMemo(() => {
        if (query === null) return [];
        const q = query.toLowerCase();
        return mentions
            .map((group) => ({
                ...group,
                items: group.items
                    .filter((item) =>
                        `${item.label} ${item.meta ?? ''}`
                            .toLowerCase()
                            .includes(q),
                    )
                    .slice(0, 6),
            }))
            .filter((group) => group.items.length);
    }, [mentions, query]);
    const flat = groups.flatMap((group) => group.items);
    const open = query !== null && flat.length > 0;
    const known = useMemo(
        () => mentions.flatMap((group) => group.items.map((i) => i.label)),
        [mentions],
    );

    const sync = (text: string, caret: number) => {
        const match = trigger.exec(text.slice(0, caret));
        setQuery(hasMentions && match ? match[2] : null);
        setActive(0);
    };
    const pick = (item: MentionItem) => {
        const el = field.current;
        const caret = el?.selectionStart ?? value.length;
        const before = value.slice(0, caret).replace(/@[^\s@]*$/, '');
        const next = `${before}@${item.label} ${value.slice(caret)}`;
        onChange(next);
        setQuery(null);
        requestAnimationFrame(() => {
            const position = before.length + item.label.length + 2;
            el?.focus();
            el?.setSelectionRange(position, position);
        });
    };
    const insertAt = () => {
        const el = field.current;
        const caret = el?.selectionStart ?? value.length;
        const pad = caret > 0 && !/\s/.test(value[caret - 1]) ? ' @' : '@';
        const next = value.slice(0, caret) + pad + value.slice(caret);
        onChange(next);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(caret + pad.length, caret + pad.length);
            sync(next, caret + pad.length);
        });
    };
    const canSend = !disabled && !busy && !processing && !!value.trim();
    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        if (canSend) onSubmit();
    };

    return (
        <div
            className={cn('kit-composer', className)}
            data-disabled={disabled ? '' : undefined}
        >
            {open && (
                <div
                    id={listId}
                    role="listbox"
                    aria-label="Mention suggestions"
                    className="kit-mention"
                >
                    {groups.map((group) => (
                        <div
                            key={group.label}
                            role="group"
                            aria-label={group.label}
                        >
                            <div className="kit-mention-group">
                                {group.label}
                            </div>
                            {group.items.map((item) => {
                                const index = flat.indexOf(item);
                                return (
                                    <button
                                        key={item.id}
                                        id={`${listId}-${index}`}
                                        type="button"
                                        role="option"
                                        aria-selected={index === active}
                                        data-active={
                                            index === active ? '' : undefined
                                        }
                                        className="kit-mention-item"
                                        style={
                                            item.tone
                                                ? ({
                                                      '--tone': item.tone,
                                                  } as React.CSSProperties)
                                                : undefined
                                        }
                                        onMouseEnter={() => setActive(index)}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => pick(item)}
                                    >
                                        <span className="kit-mention-icon [&>svg]:size-3.5">
                                            {item.icon ?? <AtSign />}
                                        </span>
                                        <span className="max-w-[55%] truncate font-medium">
                                            {item.label}
                                        </span>
                                        <span className="kit-mention-meta">
                                            {item.meta}
                                        </span>
                                        {index === active && (
                                            <kbd className="kit-kbd">↵</kbd>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}
            <label htmlFor={fieldId} className="sr-only">
                {label}
            </label>
            <textarea
                ref={field}
                id={fieldId}
                rows={rows}
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                role={hasMentions ? 'combobox' : undefined}
                aria-expanded={hasMentions ? open : undefined}
                aria-controls={open ? listId : undefined}
                aria-autocomplete={hasMentions ? 'list' : undefined}
                aria-activedescendant={open ? `${listId}-${active}` : undefined}
                onChange={(e) => {
                    onChange(e.target.value);
                    sync(e.target.value, e.target.selectionStart);
                }}
                onClick={(e) => sync(value, e.currentTarget.selectionStart)}
                onBlur={() => setQuery(null)}
                onKeyDown={(e) => {
                    if (open) {
                        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                            e.preventDefault();
                            const step = e.key === 'ArrowDown' ? 1 : -1;
                            setActive(
                                (i) => (i + step + flat.length) % flat.length,
                            );
                            return;
                        }
                        if (e.key === 'Enter' || e.key === 'Tab') {
                            e.preventDefault();
                            pick(flat[active]);
                            return;
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            setQuery(null);
                            return;
                        }
                    }
                    if (e.key === 'Backspace') {
                        const el = e.currentTarget;
                        const caret = el.selectionStart;
                        if (caret !== el.selectionEnd) return;
                        const before = value.slice(0, caret);
                        const hit = known.find(
                            (name) =>
                                before.endsWith(`@${name} `) ||
                                before.endsWith(`@${name}`),
                        );
                        if (hit) {
                            e.preventDefault();
                            const cut = before.endsWith(' ')
                                ? hit.length + 2
                                : hit.length + 1;
                            onChange(
                                value.slice(0, caret - cut) +
                                    value.slice(caret),
                            );
                            requestAnimationFrame(() =>
                                el.setSelectionRange(caret - cut, caret - cut),
                            );
                        }
                    }
                    if (
                        submitOnEnter &&
                        e.key === 'Enter' &&
                        !e.shiftKey &&
                        !e.nativeEvent.isComposing
                    ) {
                        e.preventDefault();
                        submit();
                    }
                }}
            />
            <div className="kit-composer-bar">
                {hasMentions && (
                    <button
                        type="button"
                        className="kit-tool"
                        aria-label="Mention a source"
                        title="Mention (@)"
                        disabled={disabled}
                        onClick={insertAt}
                    >
                        <AtSign className="size-4" />
                    </button>
                )}
                {tools}
                <span className="min-w-0 flex-1 truncate px-1 text-[11.5px] text-[var(--kit-faint)]">
                    {hint}
                </span>
                {busy && onStop ? (
                    <button
                        type="button"
                        className="kit-send"
                        data-stop=""
                        aria-label="Stop run"
                        onClick={onStop}
                    >
                        <Square className="size-3 fill-current" />
                    </button>
                ) : (
                    <button
                        type="button"
                        className="kit-send"
                        aria-label={sendLabel}
                        disabled={!canSend}
                        onClick={() => submit()}
                    >
                        <ArrowUp className="size-4" strokeWidth={2.4} />
                    </button>
                )}
            </div>
        </div>
    );
}
