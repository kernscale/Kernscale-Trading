import { useEffect, useRef } from 'react';
import { xsrfHeader } from '@/components/hub/research-attachments';
import type { ResearchEvent, Session } from '@/types/hub';

/** Acknowledges the highest saved event actually shown for the open session. */
export function useSessionRead(
    activeSession: Session | null,
    sessions: Session[],
    liveEvents: ResearchEvent[],
    visible: boolean,
) {
    const seenThrough = useRef<Record<string, number>>({});
    useEffect(() => {
        if (!activeSession || !visible) return;
        const sessionId = String(activeSession.id);
        const runIds = new Set(
            activeSession.runs?.map((run) => String(run.id)),
        );
        const row = sessions.find((s) => s.id === activeSession.id);
        const through = Math.max(
            0,
            ...liveEvents
                .filter((event) => runIds.has(String(event.research_run_id)))
                .map((event) => event.id),
        );
        const seen = Math.max(
            row?.last_seen_event_id ?? 0,
            seenThrough.current[sessionId] ?? 0,
        );
        if (!through || through <= seen) return;
        const controller = new AbortController();
        let cancelled = false;
        let retry: ReturnType<typeof setTimeout>;
        const acknowledge = async () => {
            if (cancelled || document.visibilityState !== 'visible') return;
            try {
                const response = await fetch(
                    `/research/sessions/${sessionId}/read`,
                    {
                        method: 'PATCH',
                        signal: controller.signal,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            ...xsrfHeader(),
                        },
                        body: JSON.stringify({ through_event_id: through }),
                    },
                );
                if (!response.ok)
                    throw new Error('Read acknowledgement failed');
                if (!cancelled) {
                    seenThrough.current[sessionId] = Math.max(
                        seenThrough.current[sessionId] ?? 0,
                        through,
                    );
                }
            } catch {
                if (!cancelled) retry = setTimeout(acknowledge, 2000);
            }
        };
        void acknowledge();
        return () => {
            cancelled = true;
            controller.abort();
            clearTimeout(retry);
        };
    }, [activeSession, liveEvents, sessions, visible]);
}
