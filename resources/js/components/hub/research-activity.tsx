import Markdown from '@/components/hub/markdown';
import { Activity, Check, Wrench } from 'lucide-react';
import {
    Trace,
    TraceCollapsible,
    TraceCollapsibleContent,
    TraceCollapsibleTrigger,
    TraceIcon,
    TraceLine,
    TraceTitle,
} from '@/components/ui/trace';
import {
    Message,
    MessageContent,
    MessageHeader,
} from '@/components/ui/message';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import type { ResearchEvent } from '@/types/hub';
// Only display runtime fields intended for the member; never stringify private debug payloads.
export default function ResearchActivity({
    events,
    streaming,
    activeRunId,
}: {
    events: ResearchEvent[];
    streaming: boolean;
    activeRunId?: string | number;
}) {
    const tokens = streaming
        ? events
              .filter(
                  (e) =>
                      e.type === 'token' &&
                      String(e.research_run_id) === String(activeRunId),
              )
              .map((e) =>
                  typeof e.payload?.text === 'string' ? e.payload.text : '',
              )
              .join('')
        : '';
    const activity = events.filter((event) => event.type !== 'token');
    return (
        <>
            {tokens && (
                <Message>
                    <MessageContent>
                        <MessageHeader>R1 Research · live output</MessageHeader>
                        <Bubble variant="ghost">
                            <BubbleContent className="text-sm leading-7">
                                <Markdown>{tokens}</Markdown>
                            </BubbleContent>
                        </Bubble>
                    </MessageContent>
                </Message>
            )}
            {activity.length > 0 && (
                <Trace className="mt-5">
                    <TraceCollapsible>
                        <TraceCollapsibleTrigger>
                            <TraceLine>
                                <TraceIcon>
                                    <Activity />
                                </TraceIcon>
                                <TraceTitle>
                                    Observed activity · {activity.length} events
                                </TraceTitle>
                            </TraceLine>
                        </TraceCollapsibleTrigger>
                        <TraceCollapsibleContent>
                            <div className="space-y-4">
                                {activity.map((event) => {
                                    const payload = event.payload ?? {};
                                    const detail =
                                        event.summary ??
                                        [
                                            payload.summary,
                                            payload.message,
                                            payload.tool,
                                            payload.name,
                                            payload.error,
                                        ].find(
                                            (value) =>
                                                typeof value === 'string',
                                        );
                                    return (
                                        <Trace key={event.id}>
                                            <TraceLine
                                                status={
                                                    event.type.includes('error')
                                                        ? 'error'
                                                        : undefined
                                                }
                                            >
                                                <TraceIcon>
                                                    {event.type.includes(
                                                        'tool',
                                                    ) ? (
                                                        <Wrench />
                                                    ) : (
                                                        <Check />
                                                    )}
                                                </TraceIcon>
                                                <TraceTitle>
                                                    {event.type.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </TraceTitle>
                                            </TraceLine>
                                            {typeof detail === 'string' && (
                                                <p className="pl-6 text-xs leading-6 whitespace-pre-wrap text-muted-foreground">
                                                    {detail}
                                                </p>
                                            )}
                                            {typeof payload.as_of ===
                                                'string' && (
                                                <p className="pl-6 text-xs text-muted-foreground">
                                                    As of {payload.as_of}
                                                </p>
                                            )}
                                            {typeof payload.evidence_id ===
                                                'string' && (
                                                <p className="pl-6 text-xs text-muted-foreground">
                                                    Evidence{' '}
                                                    {payload.evidence_id}
                                                </p>
                                            )}
                                            {typeof payload.text === 'string' &&
                                                event.type !== 'completed' && (
                                                    <p className="pl-6 text-xs leading-6 whitespace-pre-wrap text-muted-foreground">
                                                        {payload.text}
                                                    </p>
                                                )}
                                        </Trace>
                                    );
                                })}
                            </div>
                        </TraceCollapsibleContent>
                    </TraceCollapsible>
                </Trace>
            )}
        </>
    );
}
