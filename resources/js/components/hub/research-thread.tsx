import { Plus } from 'lucide-react';
import Markdown from '@/components/hub/markdown';
import { CopyButton } from '@/components/kit/collab';
import { MessageScrollerItem } from '@/components/ui/message-scroller';
import type { Session } from '@/types/hub';

const starters = [
    'Bull and bear case for NVDA',
    'What moved 10-year yields this week?',
    'Stress-test a thesis',
    'Compare semiconductor earnings quality',
];

export function ThreadMessages({ session }: { session: Session }) {
    return (
        <div className="space-y-8">
            {session.messages?.map((message) => (
                <MessageScrollerItem
                    key={message.id}
                    messageId={String(message.id)}
                    scrollAnchor={message.role === 'user'}
                >
                    {message.role === 'user' ? (
                        <div className="kit-user-bubble">{message.content}</div>
                    ) : (
                        <div className="kit-assistant">
                            <span className="kit-mark" aria-hidden>
                                K
                            </span>
                            <div className="min-w-0 pt-0.5">
                                <span className="sr-only">
                                    {message.role === 'assistant'
                                        ? 'Research'
                                        : message.role}
                                    :
                                </span>
                                {message.role === 'assistant' ? (
                                    <>
                                        <Markdown>{message.content}</Markdown>
                                        <div className="kit-actions">
                                            <CopyButton
                                                text={message.content}
                                                label="Copy answer"
                                            />
                                        </div>
                                    </>
                                ) : (
                                    <p className="whitespace-pre-wrap text-[var(--kit-soft)]">
                                        {message.content}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </MessageScrollerItem>
            ))}
        </div>
    );
}

export function ThreadWelcome({
    session,
    onStarter,
    onCreate,
    creating,
}: {
    session: Session | null;
    onStarter: (text: string) => void;
    onCreate: () => void;
    creating: boolean;
}) {
    return (
        <div className="flex min-h-[48vh] flex-col items-center justify-end pb-8 text-center">
            <span
                className="kit-mark mb-5 !size-10 !rounded-xl text-lg"
                aria-hidden
            >
                K
            </span>
            <h2 className="text-[26px] leading-tight font-medium tracking-tight md:text-[30px]">
                {session ? 'What should we look into?' : 'Start researching'}
            </h2>
            {session ? (
                <div className="mt-7 flex flex-wrap justify-center gap-2">
                    {starters.map((text) => (
                        <button
                            key={text}
                            type="button"
                            className="kit-starter !rounded-full !px-3.5 !py-1.5"
                            onClick={() => onStarter(text)}
                        >
                            {text}
                        </button>
                    ))}
                </div>
            ) : (
                <button
                    type="button"
                    className="kit-tool mt-7 !h-10 !rounded-xl !bg-foreground !px-4 !text-[13px] !font-medium !text-background"
                    disabled={creating}
                    onClick={onCreate}
                >
                    <Plus className="size-4" />
                    New session
                </button>
            )}
        </div>
    );
}
