import { Head, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    FileText,
    Lightbulb,
    NotebookPen,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import OutputDetail from '@/components/hub/output-detail';
import { displayValue, Errors } from '@/components/hub/shared';
import { Composer } from '@/components/kit/composer';
import { Quiet, Segment, StatusMark } from '@/components/kit/micro';
import type { Output, RecordData } from '@/types/hub';

type Filter = 'all' | 'draft' | 'review' | 'published';
const matches = (output: Output, filter: Filter) =>
    filter === 'all' ||
    (filter === 'draft' &&
        ['draft', 'changes_requested'].includes(output.status)) ||
    (filter === 'review' && output.status === 'in_review') ||
    (filter === 'published' && output.status === 'published');
const kindIcon = (kind: string) =>
    kind === 'strategy'
        ? Lightbulb
        : kind === 'document'
          ? FileText
          : NotebookPen;
const shortDate = (value: unknown) => {
    const time = typeof value === 'string' ? Date.parse(value) : Number.NaN;
    return Number.isNaN(time)
        ? displayValue(value)
        : new Date(time).toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          });
};

export default function Outputs({
    outputs = [],
    runs = [],
}: {
    outputs?: Output[];
    runs?: RecordData[];
}) {
    const [selectedId, setSelectedId] = useState<Output['id'] | null>(null);
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [composing, setComposing] = useState(false);
    const form = useForm({ title: '', kind: 'research', content: '' });
    const auth = usePage().props.auth as {
        roles?: string[];
        user?: { id?: number; roles?: (string | { name: string })[] };
    };
    const roles = [
        ...(auth.roles ?? []),
        ...(auth.user?.roles ?? []).map((r) =>
            typeof r === 'string' ? r : r.name,
        ),
    ];
    const owner = roles.includes('owner');
    const canResearch = roles.some((role) =>
        ['researcher', 'owner'].includes(role),
    );
    const selected = outputs.find((output) => output.id === selectedId) ?? null;
    const visible = useMemo(
        () =>
            outputs.filter(
                (output) =>
                    matches(output, filter) &&
                    output.title.toLowerCase().includes(query.toLowerCase()),
            ),
        [outputs, filter, query],
    );
    const count = (value: Filter) =>
        outputs.filter((output) => matches(output, value)).length;

    return (
        <>
            <Head title="Agent outputs" />
            {canResearch && composing && (
                <form
                    className="mx-auto mb-8 max-w-3xl space-y-3"
                    onSubmit={(e) => e.preventDefault()}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <input
                            required
                            aria-label="Title"
                            placeholder="Untitled output"
                            className="min-w-0 flex-1 bg-transparent text-xl font-medium outline-none placeholder:text-[var(--kit-faint)]"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <Segment
                            label="Type"
                            value={form.data.kind}
                            onChange={(value) => form.setData('kind', value)}
                            options={[
                                { value: 'research', label: 'Research' },
                                { value: 'strategy', label: 'Strategy' },
                                { value: 'document', label: 'Document' },
                            ]}
                        />
                    </div>
                    <Composer
                        label="Content"
                        sendLabel="Save draft"
                        rows={6}
                        submitOnEnter={false}
                        value={form.data.content}
                        onChange={(value) => form.setData('content', value)}
                        processing={form.processing || !form.data.title.trim()}
                        placeholder="Thesis, evidence, risks…"
                        onSubmit={() =>
                            form.post('/outputs', {
                                onSuccess: () => {
                                    form.reset();
                                    setComposing(false);
                                },
                            })
                        }
                    />
                    <Errors errors={form.errors} />
                </form>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Segment
                    label="Filter outputs"
                    value={filter}
                    onChange={setFilter}
                    options={[
                        { value: 'all', label: 'All', count: count('all') },
                        {
                            value: 'draft',
                            label: 'Drafts',
                            count: count('draft'),
                        },
                        {
                            value: 'review',
                            label: 'In review',
                            count: count('review'),
                        },
                        {
                            value: 'published',
                            label: 'Published',
                            count: count('published'),
                        },
                    ]}
                />
                <label className="relative ml-auto min-w-0 flex-1 sm:w-56 sm:flex-none">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-[var(--kit-faint)]" />
                    <input
                        aria-label="Search outputs"
                        placeholder="Search"
                        className="hub-input !h-9 !py-0 !pl-8 !text-[13px]"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </label>
                {canResearch && (
                    <button
                        type="button"
                        className="hub-button primary !min-h-9"
                        onClick={() => setComposing((value) => !value)}
                        aria-expanded={composing}
                    >
                        {composing ? (
                            <X className="size-4" />
                        ) : (
                            <Plus className="size-4" />
                        )}
                        {composing ? 'Close' : 'New'}
                    </button>
                )}
            </div>

            <div
                className={`grid items-start gap-4 ${selected ? 'xl:grid-cols-[minmax(300px,380px)_minmax(0,1fr)]' : ''}`}
            >
                <section
                    aria-label="Your library"
                    className={`rounded-2xl border border-[var(--kit-line)] bg-[var(--kit-surface)] p-1.5 ${selected ? 'max-xl:hidden' : ''}`}
                >
                    {visible.length ? (
                        <ul
                            className={
                                selected ? '' : 'grid gap-0.5 md:grid-cols-2'
                            }
                        >
                            {visible.map((output) => {
                                const Icon = kindIcon(output.kind);
                                return (
                                    <li key={output.id}>
                                        <button
                                            type="button"
                                            className="kit-row"
                                            aria-current={
                                                output.id === selectedId
                                            }
                                            onClick={() =>
                                                setSelectedId(output.id)
                                            }
                                        >
                                            <span className="grid size-9 shrink-0 place-items-center rounded-xl border border-[var(--kit-line)] bg-[var(--background)]">
                                                <Icon className="size-4 text-[var(--kit-soft)]" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[14px] font-medium">
                                                    {output.title}
                                                </span>
                                                <span className="mt-0.5 block truncate text-[12px] text-[var(--kit-faint)] capitalize">
                                                    {output.kind} ·{' '}
                                                    {output.author?.name ??
                                                        'Research'}{' '}
                                                    ·{' '}
                                                    {shortDate(
                                                        output.created_at,
                                                    )}
                                                </span>
                                            </span>
                                            <StatusMark
                                                status={output.status}
                                                label={output.status.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                                className="capitalize max-sm:[&>span]:hidden"
                                            />
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="px-4 py-10 text-center text-[13px] text-[var(--kit-faint)]">
                            {outputs.length ? 'No matches' : 'No outputs yet'}
                        </p>
                    )}
                </section>
                {selected && (
                    <section className="min-w-0 rounded-2xl border border-[var(--kit-line)] bg-[var(--background)]">
                        <OutputDetail
                            key={selected.id}
                            output={selected}
                            userId={auth.user?.id}
                            owner={owner}
                            onClose={() => setSelectedId(null)}
                        />
                    </section>
                )}
            </div>

            <section className="mt-8" aria-labelledby="receipts-title">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2
                        id="receipts-title"
                        className="flex items-center gap-2 text-[15px] font-medium"
                    >
                        <CalendarClock className="size-4 text-[var(--kit-faint)]" />
                        Scheduled runs
                    </h2>
                </div>
                {runs.length ? (
                    <ul className="divide-y divide-[var(--kit-line)] rounded-2xl border border-[var(--kit-line)]">
                        {runs.map((run, index) => (
                            <li
                                key={displayValue(run.id, String(index))}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3"
                            >
                                <span
                                    title={displayValue(run.status).replaceAll(
                                        '_',
                                        ' ',
                                    )}
                                >
                                    <StatusMark
                                        status={displayValue(run.status, '')}
                                    />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[14px]">
                                    {displayValue(run.title, 'Scheduled run')}
                                    {typeof run.symbol === 'string' && (
                                        <span className="ml-2 font-mono text-[12px] text-[var(--kit-faint)]">
                                            {run.symbol}
                                        </span>
                                    )}
                                </span>
                                {typeof run.reason === 'string' && (
                                    <span className="w-full truncate text-[12px] text-[var(--kit-faint)] sm:order-last sm:pl-[22px]">
                                        {run.reason}
                                    </span>
                                )}
                                {run.has_report === true && (
                                    <span className="kit-pill">Report</span>
                                )}
                                <span className="w-14 text-right text-[12px] text-[var(--kit-faint)] tabular-nums">
                                    {shortDate(run.created_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <Quiet>None yet</Quiet>
                )}
            </section>
        </>
    );
}
