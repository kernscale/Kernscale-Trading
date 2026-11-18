import Markdown from '@/components/hub/markdown';
import { AlertTriangle, BookOpen, Check, Wrench } from 'lucide-react';
import { CallChip, ThoughtLine } from '@/components/kit/micro';
import type { ResearchEvent } from '@/types/hub';

const text = (value: unknown) =>
    typeof value === 'string' ? value : undefined;

// Only display runtime fields intended for the member; never stringify private debug payloads.
export default function ResearchActivity({
    events,
    streaming,
    activeRunId,
    startedAt,
    label,
}: {
    events: ResearchEvent[];
    streaming: boolean;
    activeRunId?: string | number;
    startedAt?: string;
    label?: string;
}) {
    const runEvents = events.filter(
        (e) =>
            activeRunId === undefined ||
            String(e.research_run_id) === String(activeRunId),
    );
    const tokens = streaming
        ? runEvents
              .filter((e) => e.type === 'token')
              .map((e) => text(e.payload?.text) ?? '')
              .join('')
        : '';
    const activity = (streaming ? runEvents : events).filter(
        (event) => event.type !== 'token',
    );
    if (!streaming && !activity.length) return null;
    return (
        <div className="kit-assistant mt-8">
            <span className="kit-mark" aria-hidden>
                K
            </span>
            <div className="min-w-0 space-y-4">
                <ThoughtLine
                    working={streaming}
                    label={label ?? 'Researching'}
                    doneLabel="Research activity"
                    startedAt={startedAt}
                    count={activity.length || undefined}
                >
                    {activity.length > 0 && (
                        <ol className="mt-3 space-y-3 border-l border-[var(--kit-line)] pl-4">
                            {activity.map((event) => {
                                const payload = event.payload ?? {};
                                const detail =
                                    event.summary ??
                                    [
                                        payload.summary,
                                        payload.message,
                                        payload.error,
                                    ]
                                        .map(text)
                                        .find(Boolean);
                                const tool =
                                    text(payload.tool) ?? text(payload.name);
                                const failed = event.type.includes('error');
                                const isTool = event.type.includes('tool');
                                return (
                                    <li key={event.id} className="space-y-1.5">
                                        <CallChip
                                            tone={failed ? 'error' : 'ok'}
                                            icon={
                                                failed ? (
                                                    <AlertTriangle />
                                                ) : isTool ? (
                                                    <Wrench />
                                                ) : payload.evidence_id ? (
                                                    <BookOpen />
                                                ) : (
                                                    <Check />
                                                )
                                            }
                                        >
                                            {event.type.replaceAll('_', ' ')}
                                            {tool && (
                                                <span className="ml-1.5 font-mono text-[11px] opacity-80">
                                                    {tool}
                                                </span>
                                            )}
                                        </CallChip>
                                        {detail && (
                                            <p className="text-[13px] leading-6 whitespace-pre-wrap text-[var(--kit-soft)]">
                                                {detail}
                                            </p>
                                        )}
                                        {(text(payload.as_of) ||
                                            text(payload.evidence_id)) && (
                                            <p className="text-[11.5px] text-[var(--kit-faint)]">
                                                {text(payload.as_of) &&
                                                    `As of ${text(payload.as_of)}`}
                                                {text(payload.as_of) &&
                                                    text(payload.evidence_id) &&
                                                    ' · '}
                                                {text(payload.evidence_id) &&
                                                    `Evidence ${text(payload.evidence_id)}`}
                                            </p>
                                        )}
                                        {text(payload.text) &&
                                            event.type !== 'completed' && (
                                                <p className="text-[13px] leading-6 whitespace-pre-wrap text-[var(--kit-soft)]">
                                                    {text(payload.text)}
                                                </p>
                                            )}
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </ThoughtLine>
                {tokens && (
                    <div className="text-[15px] leading-7">
                        <Markdown>{tokens}</Markdown>
                    </div>
                )}
            </div>
        </div>
    );
}
