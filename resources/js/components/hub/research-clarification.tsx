import { router } from '@inertiajs/react';
import { MessageCircleQuestion } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Errors } from '@/components/hub/shared';
import { cn } from '@/lib/utils';
import type { ResearchEvent } from '@/types/hub';

type Question = {
    id: string;
    header: string;
    question: string;
    options: { label: string; description?: string }[];
};
type Request = {
    request_id: string;
    expires_at?: string;
    questions: Question[];
};

const settled = new Set([
    'clarification_accepted',
    'clarification_consumed',
    'clarification_closed',
]);

/** The newest clarification of a run that has not been answered, consumed or closed. */
export function openClarification(
    events: ResearchEvent[],
    runId?: string | number,
): Request | null {
    if (runId === undefined) return null;
    const runEvents = events.filter(
        (event) => String(event.research_run_id) === String(runId),
    );
    const done = new Set(
        runEvents
            .filter((event) => settled.has(event.type))
            .map((event) => String(event.payload?.request_id)),
    );
    const request = runEvents
        .filter((event) => event.type === 'clarification_requested')
        .map((event) => event.payload as unknown as Request)
        .reverse()
        .find(
            (payload) =>
                payload?.request_id &&
                Array.isArray(payload.questions) &&
                !done.has(payload.request_id),
        );
    return request ?? null;
}

function useCountdown(until?: string) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, []);
    const end = until ? Date.parse(until) : Number.NaN;
    if (Number.isNaN(end)) return null;
    const left = Math.max(0, Math.round((end - now) / 1000));
    return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
}

export default function ResearchClarification({
    runId,
    request,
}: {
    runId: string | number;
    request: Request;
}) {
    const [answers, setAnswers] = useState<Record<string, string>>({});
    const [other, setOther] = useState<Record<string, boolean>>({});
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const left = useCountdown(request.expires_at);
    const complete = request.questions.every((q) => answers[q.id]?.trim());
    const expired = left === '0:00';

    const submit = () =>
        router.post(
            `/research/runs/${runId}/clarifications/${request.request_id}/answer`,
            {
                answers: request.questions.map((q) => ({
                    id: q.id,
                    answer: answers[q.id].trim(),
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => setSent(true),
                onError: (e) => setErrors(e),
            },
        );

    if (sent)
        return (
            <p role="status" className="mt-6 text-[13px] text-[var(--kit-faint)]">
                Answer sent. Research continues.
            </p>
        );

    return (
        <section
            aria-label="Research needs your input"
            className="mt-6 rounded-2xl border border-[var(--kit-line-strong)] bg-[var(--kit-surface)] p-4"
        >
            <header className="mb-3 flex items-center gap-2 text-[13px]">
                <MessageCircleQuestion className="size-4 text-primary" />
                <span className="font-medium">Needs your input</span>
                {left && (
                    <span className="ml-auto text-[var(--kit-faint)] tabular-nums">
                        {expired ? 'Expired' : left}
                    </span>
                )}
            </header>
            <div className="space-y-5">
                {request.questions.map((q) => (
                    <fieldset key={q.id} disabled={sending || expired}>
                        <legend className="mb-2 text-[14px] leading-snug">
                            <span className="kit-pill mr-2 align-middle">
                                {q.header}
                            </span>
                            {q.question}
                        </legend>
                        <div className="flex flex-col gap-1.5">
                            {q.options.map((option) => {
                                const on =
                                    !other[q.id] &&
                                    answers[q.id] === option.label;
                                return (
                                    <button
                                        key={option.label}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => {
                                            setOther((s) => ({
                                                ...s,
                                                [q.id]: false,
                                            }));
                                            setAnswers((s) => ({
                                                ...s,
                                                [q.id]: option.label,
                                            }));
                                        }}
                                        className={cn(
                                            'rounded-xl border px-3 py-2 text-left text-[13px] transition-colors',
                                            on
                                                ? 'border-primary bg-primary/10'
                                                : 'border-[var(--kit-line)] hover:bg-[var(--kit-hover)]',
                                        )}
                                    >
                                        <span className="block font-medium">
                                            {option.label}
                                        </span>
                                        {option.description && (
                                            <span className="mt-0.5 block text-[12px] text-[var(--kit-faint)]">
                                                {option.description}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                            {other[q.id] ? (
                                <input
                                    autoFocus
                                    aria-label={`Your own answer: ${q.header}`}
                                    maxLength={8192}
                                    placeholder="Your answer"
                                    className="hub-input !rounded-xl !py-2 !text-[13px]"
                                    value={answers[q.id] ?? ''}
                                    onChange={(e) =>
                                        setAnswers((s) => ({
                                            ...s,
                                            [q.id]: e.target.value,
                                        }))
                                    }
                                />
                            ) : (
                                <button
                                    type="button"
                                    className="self-start px-1 text-[12.5px] text-[var(--kit-faint)] hover:text-foreground"
                                    onClick={() => {
                                        setOther((s) => ({ ...s, [q.id]: true }));
                                        setAnswers((s) => ({ ...s, [q.id]: '' }));
                                    }}
                                >
                                    Something else…
                                </button>
                            )}
                        </div>
                    </fieldset>
                ))}
            </div>
            <Errors errors={errors} />
            <div className="mt-4 flex justify-end">
                <button
                    type="button"
                    className="hub-button primary !h-8 !px-3.5 !text-[13px]"
                    disabled={!complete || sending || expired}
                    onClick={submit}
                >
                    {sending ? 'Sending…' : 'Send answer'}
                </button>
            </div>
        </section>
    );
}
