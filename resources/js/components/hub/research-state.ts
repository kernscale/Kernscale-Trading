import type { ResearchEvent } from '@/types/hub';
export const activeRunStates = [
    'queued',
    'running',
    'waiting',
    'waiting_for_input',
    'pending',
    'dispatching',
    'cancel_requested',
    'collecting',
];
export function mergeEvents(
    previous: ResearchEvent[],
    incoming: ResearchEvent[],
) {
    return [
        ...new Map(
            [...previous, ...incoming].map((event) => [event.id, event]),
        ).values(),
    ].sort((a, b) => a.id - b.id);
}
export function isPublicSource(url: string | undefined) {
    if (!url) return false;
    try {
        return ['http:', 'https:'].includes(new URL(url).protocol);
    } catch {
        return false;
    }
}
export function evidenceSources(events: ResearchEvent[]) {
    const sources = new Map<
        string,
        {
            url: string;
            title?: string;
            citationIndex?: number;
            evidenceId?: string;
        }
    >();
    for (const event of events) {
        const record = event.payload ?? {};
        const payload =
            record.payload &&
            typeof record.payload === 'object' &&
            !Array.isArray(record.payload)
                ? (record.payload as Record<string, unknown>)
                : record;
        for (const field of ['sources', 'results', 'pages']) {
            const rows = payload[field];
            if (!Array.isArray(rows)) continue;
            for (const item of rows) {
                if (!item || typeof item !== 'object') continue;
                const row = item as Record<string, unknown>;
                if (typeof row.url !== 'string' || !isPublicSource(row.url))
                    continue;
                sources.set(row.url, {
                    ...sources.get(row.url),
                    url: row.url,
                    ...(typeof row.title === 'string'
                        ? { title: row.title }
                        : {}),
                    ...(typeof row.citation_index === 'number'
                        ? { citationIndex: row.citation_index }
                        : {}),
                    ...(typeof record.id === 'string'
                        ? { evidenceId: record.id }
                        : {}),
                });
            }
        }
    }
    return [...sources.values()];
}

export function runStatusLabel(status: string) {
    if (status === 'collecting') return 'Collecting results';
    if (status === 'waiting_for_input') return 'Needs your input';
    return status.replaceAll('_', ' ');
}

export type ResearchIntent = { text: string; key: string };
export function researchIntent(
    intents: Map<string, ResearchIntent>,
    session: string,
    text: string,
): string {
    const current = intents.get(session);
    if (current?.text === text) return current.key;
    const key = crypto.randomUUID();
    intents.set(session, { text, key });
    return key;
}

export function completeResearchIntent(
    intents: Map<string, ResearchIntent>,
    session: string,
    key: string,
) {
    if (intents.get(session)?.key === key) intents.delete(session);
}
