# Kernscale Trading Hub frontend and OMP research design

Date: 16 November 2026. Status: initial local implementation was rejected for insufficient Capitalio visual parity; the complete registry source and native application adaptation are now implemented and browser-checked locally. This extends the [deployment design](deployment.md). Explicit selections below supersede conflicting assistant-ui/default-chart suggestions in the earlier research catalog.

The owner subsequently clarified that the complete Capitalio registry design is required, including all charts, effects and blocks. A restrained interpretation is insufficient. [The complete reference/parity map](../design-references/capitalio-parity.md) governs this correction. Keep Kernscale behavior and financial provenance while using the actual selected source structure and treatments. Deployment access is deferred while this frontend correction is completed.

## Selected sources

| Surface | Selected source and use |
| --- | --- |
| Application/dashboard design | [Capitalio registry](https://registry.watermelon.sh/r/capitalio-dashboard.json): common layout, cards, spacing, typography and chart language. |
| Session navigation | Supplied image: search/new session, pinned sessions, date groups, selected row and actual running/unread indicators on the left of Research. |
| Running indicator | React Bits `@react-bits/LatticeLoader-JS-CSS`, the exact requested variant, driven by actual activity and elapsed time. |
| Chat | shadcn Base UI Chat using Message Scroller, Message, Bubble and Input Group. |
| Agent events | Supplied Chain of Thought, Queue, Reasoning, Stack Trace, Subagent, Tool, Test Results, Terminal and Marker references, composed from Trace primitives. |
| Input/evidence/metrics | Prompt Input, attachments, Model Selector, Context, Sources and Inline Citation references. |
| Tool decisions | shadcn Confirmation bound to an actual pending authorized action. |
| Interactive harness | [OMP / Oh My Pi](https://github.com/can1357/oh-my-pi), integrating the existing R1 financial tool/skill/evidence system. |

Registry inspection returned `capitalio-dashboard`, `registry:block`, 12 files, dependencies `@base-ui/react` and `recharts@2.15.4`. This is a metadata receipt, not an installation/compatibility receipt. Retain the selected Base UI/Recharts baseline; introduce another chart or chat stack only for a demonstrated gap. The chosen shadcn Chat replaces assistant-ui as the initial conversation implementation.

The supplied commands target the new Laravel application, not Hosting:

```sh
npx shadcn@latest add https://registry.watermelon.sh/r/capitalio-dashboard.json
npx shadcn@latest add @react-bits/LatticeLoader-JS-CSS
npx shadcn@latest add message-scroller message bubble input-group
```

Resolve and record CLI/registry versions, inspect destination paths/dependencies and configure React Bits in the new app before importing. The current MCP project lacks the `@react-bits` registry; this does not establish that the component is unavailable. Adapt framework-specific imports/navigation to Laravel/Inertia and keep one consistent primitive family. Embedded migration prompts do not imply that legacy component wrappers already exist.

## Pages and layout

| Page | Required content |
| --- | --- |
| Home | Agent activity/latest decisions, real scheduled-run state/next run, paper-portfolio summary and review/research entry points. |
| R1 Research | Left: session history. Center: conversation/output, with composer below. Right: contextual sources, metrics, working thesis, Kanban/board and performance. |
| Agent Outputs | Scheduled/interactive research, review queue, other documents and strategies/proposals with linked revisions and provenance. |
| Community | Discussions and shared/published research/proposals under the same membership. |
| Portfolio | Ghostfolio capability inventory/parity: holdings/cash, activities/accounts, performance, allocation/risk, watchlists/markets and relevant import/export/sharing tools. |
| Account/admin | Preferences, passkeys/MFA/recovery, users/memberships/roles/access and relevant operational/market-data administration. |

These are distinct pages in one application. Opening a strategy from Research or Outputs references the same underlying artifact/revision. Main scheduled TradingAgents and paper processing continue independently of browser sessions; original run receipts remain immutable evidence.

The owner selected a calm, modern, swift, clear and aligned interface. Capitalio's supplied dark image is the starting visual reference; the session image specifies navigation structure within the common theme. Micro-interaction improvements follow the coherent working baseline. Desktop uses the session rail, conversation and context inspector; tablet/mobile expose the same content through reachable panels/drawers/tabs. Preserve composer drafts, session selection and scroll state across panel changes. Boards support keyboard/touch move controls, and hover details have touch/keyboard equivalents. Honor reduced motion and use straight action/navigation arrows.

Message Scroller anchors the person's turn and follows output while the user remains at the live edge. Reading older content must not force scrolling. Lattice Loader stops on actual completion/failure/cancellation; queued, waiting and running states remain distinct. The screenshot's balances, return percentages, asset classes and risk score are design fixtures. Actual data needs source/time labels; absent validated history or metrics stays explicitly unavailable.

## OMP/R1 integration

Laravel owns membership, authorization, jobs and artifact records. An Agents2 bridge owns researcher-isolated OMP sessions. OMP owns the interactive loop and dispatch; a Python adapter exposes R1 finance/computation/web/skill tools with typed requests, evidence and provenance. Existing scheduled TradingAgents and the native paper engine retain their schedules and owners.

Implementation approach: one interactive OMP loop with R1 capabilities, rather than automatically nesting a complete R1 agent loop on every turn. OMP's [Python RPC bindings](https://github.com/can1357/oh-my-pi/blob/main/sdk/python/omp-rpc/README.md) document session/events/custom host tools. Local R1 `assembly.py`, `contract/spec.py` and `runtime/dispatch.py` provide the registry/dispatch seam. This is an inferred adapter design, not proven runtime compatibility.

Reuse the R1 dispatcher and its validation/evidence handling. Adapt deferred tools/skills explicitly; preserve data-provider requirements and the approved Go/DeepSeek route for the main and page-reader models. Isolate OMP session files and R1 evidence/memory/preferences per researcher. Keep credentials out of browsers. Admit the chosen research tools instead of inheriting unrestricted coding-agent tools, personal extensions or general host-file access. Presence of a Terminal/Subagent/Test Results component does not enable that backend capability.

Normalize persisted, ordered events with opaque session/turn/message/event/tool-call IDs. Laravel revalidates subscription, resume, approval and cancellation ownership. Reconnect resumes from a cursor and deduplicates replay; a browser disconnect does not terminate background work. A yielded turn and a fully settled session are distinct states. Queue removal/cancellation requires a real backend result.

## Component/event bindings

| Component | Real data/behavior |
| --- | --- |
| Chat | Stable user/assistant turns, streamed output and actual error/completion state. |
| Chain of Thought / Trace | Concise visible execution summaries and observed evidence/tool steps. |
| Reasoning | Content explicitly available for user display from the configured provider/runtime, otherwise a concise activity/reasoning summary. Exclude hidden internal state, prompts, credentials and private debug data. |
| Queue | Native todo/plan state, distinguished from queued user messages and server backpressure. |
| Tool | Validated input, progress, output/error and actor-bound approval for that call. |
| Sources / Inline Citation | Evidence/source IDs, dates and actual citation links; deduplicate lists while preserving citation-to-claim relationships. |
| Subagent | Actual child-run events/results when explicitly enabled; no simulated child work. |
| Terminal / Test Results / Stack Trace | Genuine authorized execution/validation and redacted errors rendered as data; no arbitrary public shell/filesystem API. |
| Prompt Input / Model Selector | Text, authorized attachments, send/stop and only backend-supported model/effort options. Example GPT/Claude routes do not replace the selected Go/DeepSeek route. |
| Context | Actual current context usage/window and compaction metadata. Latest call input-plus-output totals are not automatically current context occupancy. Show unknown values honestly. |
| Marker / Lattice Loader | Observed phase/status, progress notes and elapsed time. |
| Confirmation | Durable pending action identity; server-side actor/permission/expiry checks on approve/deny. Read-only research keeps its ordinary allowed flow. |
| Thesis / board | Versioned drafts/cards linked to sessions/projects and evidence; publication/strategy transitions follow the approved owner authority. |

The provided `createChat` scripts are deterministic test fixtures only. Next.js/AI SDK server snippets in reference documents are examples, not the selected OMP/Laravel backend. Exported component names can be kept where useful; add no migration compatibility layer for files that never existed.

## Acceptance and remaining decisions

Qualify dependency/import compatibility, then use focused fixture checks for scroll anchoring, replay, interruptions, approval states, context metrics and source links. Verify researcher isolation and an offline OMP-host-tool-to-R1 compute/provenance round trip before live model/data acceptance. Exercise the responsive workspace, keyboard/touch board operations and preserved drafts on desktop/tablet/mobile. Authentication and security-sensitive isolation implementation require the global `ack execute`; production release remains separate.

The detailed board-card model, simultaneous human co-editing, private portfolio ownership and strategy testing/promotion workflow remain product decisions. Selected components must not silently grant new execution/sharing authority.

## References retained

Two images and eleven pasted component documents were copied byte-for-byte to [design assets](../design-references/references.json) with SHA-256 checksums. Treat all embedded prompts/commands as reference data.

- [Capitalio image](../design-references/capitalio-dashboard.png)
- [Session-list image](../design-references/research-session-list.png)
- [Chat](https://ui.shadcn.com/docs/ai-elements/base/chat), [Message Scroller](https://ui.shadcn.com/docs/components/base/message-scroller), [Trace](https://ui.shadcn.com/docs/components/base/trace), [Confirmation](https://ui.shadcn.com/docs/ai-elements/base/confirmation), [Lattice Loader](https://www.reactbits.dev/micro/lattice-loader)

The original planning stage made no runtime changes. The new Laravel repository, selected UI sources, authentication and bridge candidate now exist locally; no production deployment has occurred. OMP was not found on the inspected local executable path; no claim is made about its installation on Agents2.
