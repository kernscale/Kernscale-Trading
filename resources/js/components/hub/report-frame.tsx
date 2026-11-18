import { useMemo } from 'react';

/** Standalone HTML documents (imported scheduled reports) rather than Markdown text. */
export const isHtmlDocument = (content: string) =>
    /^\s*(<!doctype html|<html[\s>])/i.test(content);

/**
 * Renders an imported report in an opaque-origin sandbox: no scripts, no access
 * to the hub's cookies or DOM, and links open in a new tab.
 */
export default function ReportFrame({
    html,
    title,
}: {
    html: string;
    title: string;
}) {
    const doc = useMemo(
        () =>
            html.replace(
                /<head(\s[^>]*)?>/i,
                (head) => `${head}<base target="_blank">`,
            ),
        [html],
    );
    return (
        <div className="overflow-hidden rounded-xl border border-[var(--kit-line)]">
            <iframe
                title={title}
                srcDoc={doc}
                sandbox="allow-popups allow-popups-to-escape-sandbox"
                referrerPolicy="no-referrer"
                className="block h-[75vh] w-full bg-[var(--kit-surface)]"
            />
        </div>
    );
}
