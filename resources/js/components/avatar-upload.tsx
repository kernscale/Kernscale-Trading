import { router, usePage } from '@inertiajs/react';
import { Camera, LoaderCircle, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { CollabAvatar } from '@/components/kit/collab';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

const accepted = ['image/jpeg', 'image/png', 'image/webp'];

/** Click or drop an image; the server crops it square and re-encodes it. */
export default function AvatarUpload({ user }: { user: User }) {
    const input = useRef<HTMLInputElement>(null);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [preview, setPreview] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [clientError, setClientError] = useState<string | null>(null);

    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    const upload = (file?: File) => {
        if (!file) return;
        if (!accepted.includes(file.type)) {
            setClientError('Choose a JPG, PNG or WebP image.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            setClientError('Choose an image up to 5 MB.');
            return;
        }
        setClientError(null);
        setPreview(URL.createObjectURL(file));
        router.post(
            '/settings/profile/avatar',
            { avatar: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setPreview(null);
                    if (input.current) input.current.value = '';
                },
            },
        );
    };

    const src = preview ?? (user.avatar as string | undefined) ?? null;

    return (
        <div className="flex items-center gap-5">
            <button
                type="button"
                aria-label="Upload profile picture"
                disabled={busy}
                onClick={() => input.current?.click()}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    upload(event.dataTransfer.files[0]);
                }}
                className={cn(
                    'group relative shrink-0 rounded-2xl outline-offset-4 transition-transform active:scale-[0.97]',
                    dragging && 'ring-2 ring-primary ring-offset-4 ring-offset-[var(--background)]',
                )}
            >
                <CollabAvatar
                    name={user.name}
                    src={src}
                    size={72}
                    className="!rounded-2xl"
                />
                <span className="absolute inset-0 flex items-center justify-center rounded-2xl bg-black/55 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 data-[busy]:opacity-100" data-busy={busy ? '' : undefined}>
                    {busy ? (
                        <LoaderCircle className="size-5 animate-spin text-white" />
                    ) : (
                        <Camera className="size-5 text-white" />
                    )}
                </span>
            </button>
            <div className="min-w-0">
                <p className="text-[15px] font-medium">{user.name}</p>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        className="hub-button !h-8 !px-3 !text-[13px]"
                        disabled={busy}
                        onClick={() => input.current?.click()}
                    >
                        {user.avatar ? 'Change photo' : 'Upload photo'}
                    </button>
                    {Boolean(user.avatar) && (
                        <button
                            type="button"
                            aria-label="Remove profile picture"
                            className="kit-tool !h-8 !min-w-8"
                            disabled={busy}
                            onClick={() =>
                                router.delete('/settings/profile/avatar', {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Trash2 className="size-4" />
                        </button>
                    )}
                </div>
                <p className="mt-2 text-[12px] text-[var(--kit-faint)]">
                    JPG, PNG or WebP · drop an image on the photo
                </p>
                <InputError
                    className="mt-1"
                    message={clientError ?? errors.avatar}
                />
            </div>
            <input
                ref={input}
                type="file"
                accept={accepted.join(',')}
                className="sr-only"
                tabIndex={-1}
                aria-hidden
                onChange={(event) => upload(event.target.files?.[0])}
            />
        </div>
    );
}
