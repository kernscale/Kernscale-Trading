import { useForm } from '@inertiajs/react';
import Markdown from '@/components/hub/markdown';
import { Errors, Status } from '@/components/hub/shared';
import {
    Item,
    ItemActions,
    ItemContent,
    ItemTitle,
} from '@/components/ui/item';
import type { Session, WorkspaceProposal } from '@/types/hub';
function ProposalDecision({ proposal }: { proposal: WorkspaceProposal }) {
    const form = useForm({ decision: 'reject' });
    const change = proposal.payload;
    const decide = (decision: 'apply' | 'reject') => {
        form.transform(() => ({ decision }));
        form.post(`/research/proposals/${proposal.id}/decision`, {
            preserveScroll: true,
        });
    };
    if (proposal.status !== 'pending')
        return (
            <div className="flex items-center justify-between gap-2 border-t border-white/5 py-3">
                <p className="text-xs">
                    {change.title ?? change.operation.replaceAll('_', ' ')}
                </p>
                <Status>{proposal.status}</Status>
            </div>
        );
    return (
        <Item variant="outline" size="sm" className="rounded-lg p-3">
            <ItemContent>
                <ItemTitle className="text-xs">
                    {change.operation.replaceAll('_', ' ')}
                </ItemTitle>
                {change.title && (
                    <p className="text-xs font-medium">{change.title}</p>
                )}
                {change.card_id && (
                    <p className="text-[10px] break-all text-muted-foreground">
                        Card {change.card_id}
                    </p>
                )}
                {change.content && (
                    <div className="max-h-48 overflow-auto text-xs">
                        <Markdown>{change.content}</Markdown>
                    </div>
                )}
                {change.status && (
                    <p className="text-xs text-muted-foreground">
                        Move to: {change.status}
                    </p>
                )}
                {change.expected_version !== undefined && (
                    <p className="text-[10px] text-muted-foreground">
                        Based on version {change.expected_version}
                    </p>
                )}
                <p className="text-[10px] leading-5 text-muted-foreground">
                    Applying changes your private workspace. The server checks
                    the current version.
                </p>
                <Errors errors={form.errors} />
            </ItemContent>
            <ItemActions className="w-full justify-end">
                <button
                    type="button"
                    className="hub-button"
                    disabled={form.processing}
                    onClick={() => decide('reject')}
                >
                    Reject
                </button>
                <button
                    type="button"
                    className="hub-button primary"
                    disabled={form.processing}
                    onClick={() => decide('apply')}
                >
                    Apply
                </button>
            </ItemActions>
        </Item>
    );
}
export default function ResearchThesis({ session }: { session: Session }) {
    const form = useForm({
        content: session.thesis ?? '',
        version: session.thesis_version ?? 1,
    });
    const stale =
        session.thesis_version !== undefined &&
        form.data.version !== session.thesis_version;
    return (
        <>
            <details open className="mb-6">
                <summary className="cursor-pointer text-xs font-medium">
                    Working thesis
                </summary>
                {session.thesis_version !== undefined ? (
                    <form
                        className="mt-4 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.patch(
                                `/research/sessions/${session.id}/thesis`,
                                {
                                    preserveScroll: true,
                                    onSuccess: (page) => {
                                        const current = page.props
                                            .activeSession as Session;
                                        if (
                                            current?.thesis_version !==
                                            undefined
                                        ) {
                                            form.setData({
                                                content: current.thesis ?? '',
                                                version: current.thesis_version,
                                            });
                                            form.setDefaults({
                                                content: current.thesis ?? '',
                                                version: current.thesis_version,
                                            });
                                        }
                                    },
                                },
                            );
                        }}
                    >
                        <label className="sr-only" htmlFor="working-thesis">
                            Working thesis
                        </label>
                        <textarea
                            id="working-thesis"
                            rows={7}
                            className="hub-input text-xs"
                            placeholder="Your thesis, assumptions and evidence…"
                            value={form.data.content}
                            onChange={(e) =>
                                form.setData('content', e.target.value)
                            }
                        />
                        {stale && (
                            <div
                                role="status"
                                className="text-xs leading-5 text-amber-200"
                            >
                                A newer thesis version is available. Your draft
                                is preserved.
                                <button
                                    type="button"
                                    className="hub-button mt-2"
                                    onClick={() => {
                                        form.setData({
                                            content: session.thesis ?? '',
                                            version: session.thesis_version!,
                                        });
                                    }}
                                >
                                    Load latest thesis
                                </button>
                            </div>
                        )}
                        <Errors errors={form.errors} />
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[10px] text-muted-foreground">
                                Version {form.data.version}
                            </span>
                            <button
                                className="hub-button"
                                disabled={form.processing || stale}
                            >
                                Save thesis
                            </button>
                        </div>
                    </form>
                ) : (
                    <p className="mt-3 text-xs leading-6 text-muted-foreground">
                        Versioned thesis editing is unavailable for this
                        session.
                    </p>
                )}
            </details>
            {!!session.proposals?.length && (
                <details open className="mb-6">
                    <summary className="cursor-pointer text-xs font-medium">
                        Proposals{' '}
                        <span className="ml-1 text-muted-foreground">
                            {session.proposals?.filter(
                                (proposal) => proposal.status === 'pending',
                            ).length ?? 0}{' '}
                            pending
                        </span>
                    </summary>
                    <div className="mt-4 space-y-3">
                        {session.proposals.map((proposal) => (
                            <ProposalDecision
                                key={proposal.id}
                                proposal={proposal}
                            />
                        ))}
                    </div>
                </details>
            )}
        </>
    );
}
