import { FileText, LoaderCircle, Paperclip, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type AttachmentReceipt = {
    id: string;
    name: string;
    media_type: string;
    size: number;
    sha256: string;
};

const accept = '.pdf,.txt,.md,.csv';

export const xsrfHeader = (): Record<string, string> => {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
};

export const fileSize = (bytes: number) =>
    bytes < 1024
        ? `${bytes} B`
        : bytes < 1024 * 1024
          ? `${Math.round(bytes / 1024)} KB`
          : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

/** Private session evidence: the list, uploads and which files go with the next question. */
export function useAttachments(sessionId?: string | number) {
    const [files, setFiles] = useState<AttachmentReceipt[]>([]);
    const [selected, setSelected] = useState<string[]>([]);
    const [uploading, setUploading] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setFiles([]);
        setSelected([]);
        setError(null);
        if (!sessionId) return;
        const controller = new AbortController();
        fetch(`/research/sessions/${sessionId}/attachments`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => data && setFiles(data.attachments ?? []))
            .catch(() => undefined);
        return () => controller.abort();
    }, [sessionId]);

    const upload = async (file: File) => {
        if (!sessionId) return;
        setError(null);
        setUploading(file.name);
        const body = new FormData();
        body.append('file', file);
        try {
            const response = await fetch(
                `/research/sessions/${sessionId}/attachments`,
                {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json', ...xsrfHeader() },
                },
            );
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message =
                    Object.values(
                        (data.errors ?? {}) as Record<string, string[]>,
                    )[0]?.[0] ??
                    data.message ??
                    'Upload failed.';
                setError(message);
                return;
            }
            setFiles((current) => [...current, data as AttachmentReceipt]);
            setSelected((current) => [...current, data.id]);
        } catch {
            setError('Upload failed. Check your connection.');
        } finally {
            setUploading(null);
        }
    };

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return {
        files,
        selected,
        uploading,
        error,
        upload,
        toggle,
        clear: () => setSelected([]),
    };
}

export type Attachments = ReturnType<typeof useAttachments>;

export function AttachButton({
    attachments,
    disabled,
}: {
    attachments: Attachments;
    disabled?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    return (
        <>
            <button
                type="button"
                className="kit-tool"
                aria-label="Attach a file"
                title="Attach PDF, TXT, Markdown or CSV"
                disabled={disabled || !!attachments.uploading}
                onClick={() => input.current?.click()}
            >
                {attachments.uploading ? (
                    <LoaderCircle className="size-4 animate-spin" />
                ) : (
                    <Paperclip className="size-4" />
                )}
            </button>
            <input
                ref={input}
                type="file"
                accept={accept}
                className="sr-only"
                tabIndex={-1}
                aria-hidden
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    if (file) void attachments.upload(file);
                    event.target.value = '';
                }}
            />
        </>
    );
}

/** Chips for files going with the next question; earlier files can be re-added. */
export function AttachmentTray({ attachments }: { attachments: Attachments }) {
    const [showAll, setShowAll] = useState(false);
    const { files, selected, uploading, error, toggle } = attachments;
    const others = files.filter((file) => !selected.includes(file.id));
    const visible = files.filter(
        (file) => selected.includes(file.id) || showAll,
    );
    if (!files.length && !uploading && !error) return null;
    return (
        <div className="mb-2 flex flex-wrap items-center gap-1.5">
            {visible.map((file) => {
                const on = selected.includes(file.id);
                return (
                    <button
                        key={file.id}
                        type="button"
                        aria-pressed={on}
                        title={`${file.name} · ${fileSize(file.size)}`}
                        onClick={() => toggle(file.id)}
                        className={cn(
                            'kit-chip max-w-[14rem] gap-1.5',
                            !on && 'opacity-55 hover:opacity-100',
                        )}
                    >
                        <FileText className="size-3.5 shrink-0" />
                        <span className="truncate">{file.name}</span>
                        {on && <X className="size-3 shrink-0 opacity-60" />}
                    </button>
                );
            })}
            {uploading && (
                <span className="kit-chip gap-1.5 opacity-70">
                    <LoaderCircle className="size-3.5 animate-spin" />
                    <span className="max-w-[10rem] truncate">{uploading}</span>
                </span>
            )}
            {others.length > 0 && (
                <button
                    type="button"
                    className="px-1.5 text-[12px] text-[var(--kit-faint)] hover:text-foreground"
                    onClick={() => setShowAll((value) => !value)}
                >
                    {showAll
                        ? 'Hide earlier files'
                        : `${others.length} earlier file${others.length === 1 ? '' : 's'}`}
                </button>
            )}
            {error && (
                <span role="alert" className="text-[12px] text-red-300">
                    {error}
                </span>
            )}
        </div>
    );
}
