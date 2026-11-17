# Private research bridge

Laravel calls this service on a private authenticated network. This bridge never starts or changes TradingAgents schedules or the Trading-CLI ledger. Authentication token and all provider keys remain server-side. Researcher IDs are server-derived Laravel identities, never browser-controlled actor claims.

Run from the repository root using an isolated environment:

```sh
uvicorn bridge.app:create_app --factory --host 127.0.0.1 --port 8863 --workers 1
```

Set `TRADING_BRIDGE_TOKEN` (at least 32 characters), `TRADING_BRIDGE_DB` (inside a coordinator-owned 0700 directory), and the protected `TRADING_BRIDGE_IDENTITIES` file. Do not expose this service directly to browsers. It does not replace Laravel membership, researcher policy, revocation or session authorization. A single coordinator holds a filesystem lock; startup fences old worker cgroups before marking unfinished runs interrupted. No automatic prompt replay occurs.

## Contract

Every request needs `Authorization: Bearer <service token>` and `X-Workspace-ID: kernscale-trading`.

| Method | Path | Result |
| --- | --- | --- |
| PUT | `/v1/researchers/{positive-id}/runs/{uuid}` | Submit immutable run; identical replay returns existing state; changed payload 409 |
| GET | same path | `{id,status,session_id}` |
| GET | same path + `/events?after=0` | `{id,status,events:[{id,type,payload,created_at}]}`; at most 500 events, monotonically increasing durable cursor |
| POST | same path + `/cancel` | Durable `cancelled` receipt before admission; admitted work stays `cancel_requested` until worker/cgroup confirmation |
| POST | same path + `/clarifications/{request_id}/answer` | Durable typed answer receipt; `accepted` is distinct from actual worker `consumed` |
| GET | `/health` | Authenticated capability state; no credential values |
| GET | `/v1/portfolio` | Read-only native portfolio projection |
| GET | `/v1/reports` | Native saved report summaries |
| GET | `/v1/reports/{uuid}` | Untrusted report artifact/content and integrity metadata |
| GET | `/v1/status` | Read-only native source status |

Submission JSON: `{workspace_id:"kernscale-trading",tenant_id:"kernscale-trading",session_id:"<uuid>",prompt:"...",model:"deepseek-v4.1-flash"}`. Unknown fields/models are refused. Sessions belong to exactly one researcher; another researcher receives 404. One run per session can be active. There are no application run-count or spending quotas. Browser polling/reconnection never owns the worker task. Terminal states are `completed`, `failed`, `cancelled`, and `interrupted`.

## Actual isolation boundary

The manual [Linux CI qualification](../docs/api/linux-isolation.md) verified actual
UID/GID dropping, cross-home denial and cgroup cancellation of detached
descendants using synthetic workers. Agents2 admission, production systemd
confinement and actual OMP/R1/provider execution remain separate requirements.

`deploy/` is an **uninstalled host admission template**, not an executed release. Real dispatch fails closed without Linux, a dedicated root-protected cgroup v2 subtree, root-supervised UID dropping, a root-owned non-writable researcher identity map, unique unprivileged UIDs/GIDs, private UID-owned homes, and explicit `runtime_qualified:true`. Each researcher has a distinct OS identity. Workers and OMP descendants join one app-owned cgroup per run; cancellation/restart fence that cgroup. No arbitrary executable, RPC operation, shell command, provider URL, UID or environment value is accepted from HTTP.

The coordinator is a small privileged supervisor in this template. Admission, capability bounding, filesystem protection, cgroup delegation and UID cross-read denial must be qualified on Agents2 before enabling it. Keep bridge DB, credentials and admissions outside researcher-accessible mounts/permissions. Secrets are admitted only into the matching worker's scrubbed environment. OMP's SDK merges its environment with its parent, so scrubbing occurs at the **outer worker** boundary. Dedicated identities must not have login, sudo or access to the native paper ledger or other researcher directories.

OMP built-ins, extensions, skills and context-file discovery are disabled. Only typed R1 host tools are registered. Bundled R1 skill controls use the R1 registry; source/evidence/plan events retain provenance. R1 evidence is fsynced per session and restored before subsequent turns. Hidden model thinking is not forwarded. Tauric synchronization/service globals are never imported or started.

Tauric data access is **optional**. Without a backend `data_key`, native data tools are omitted; web research and local finance computations remain available. Go/DeepSeek supplies the OMP main model and R1 page reader using `OPENCODE_API_KEY`. No user Tauric cloud account is required. Data unavailable stays unavailable.

## Pins and verification

OMP CLI and SDK must be built from the same official revision: `6f03614d761366972e80e84e25d1418ed678d2c7` (resolved through public `git ls-remote`; SDK metadata and source downloaded from that revision). SDK dependency uses the exact commit in `pyproject.toml`. Install R1 separately at `816efb4643a7c33e2943b1a64f08971638f517e0`; do not launch its service app.

```sh
python3 -m unittest bridge.tests.test_bridge -v
# With pinned SDK and R1 on PYTHONPATH and their dependencies installed:
python3 -m unittest bridge.tests.test_native_adapter -v
```

The native test uses the actual SDK subprocess transport, actual R1 calc dispatch, persisted evidence replay and schema rejection; its OMP process is a deterministic offline fixture. It does not contact any provider. Linux cgroup/UID acceptance, actual OMP CLI startup/session continuation, provider compatibility and Ops-to-Agents2 networking remain separate qualification checks. Report viewer sanitization/sandboxing belongs to Laravel.

Review hardening: Go main/reader share the verified `https://opencode.ai/inference/go/openai/v1` base. Public page retrieval uses a per-redirect pinned public IP with original Host/TLS SNI and no ambient proxies, closing the upstream R1 request-hook DNS-rebinding window. Privileged worker source/ancestors and OMP binary/ancestors must be root-owned and non-writable by other users; admission additionally requires the verified `omp_sha256`. The root supervisor writes the request only after moving the waiting worker into its cgroup. Host qualification must still verify installed dependencies/artifact provenance, root-supervisor confinement and UID/cgroup behavior.

The researcher admission map contains credentials and must have mode **0600** and root ownership; read access by other identities is refused, as well as write access. Native read-only mark/receipt limitations and writer-retirement dependencies are detailed in `PORTFOLIO.md`.

## Review contract additions

Events include `has_more` and `next_cursor`; drain every page even when `status` is terminal. The next page starts strictly after `next_cursor`. Hardware backpressure uses a single worker by default (`TRADING_BRIDGE_WORKERS`, trusted operator setting); pending runs remain durably `queued`. This is concurrency capacity, not a daily/user/run quota. Shutdown waits for started work; queued work is reconciled as interrupted on restart.

Run requests optionally include an immutable `workspace` snapshot: `{thesis:{content:"",version:1},board:[]}` by default. `workspace_read` reads only that snapshot; `workspace_propose` validates card IDs/versions and emits flat `workspace_proposal` payloads for Laravel human approval. These tools never change application state or access an arbitrary API.

Continuation saves the SDK-reported session JSONL file inside the owned session directory and subsequently calls native `switch_session(file)`, refusing directories, escaping paths and symlinks. The exact pinned SDK also supports `open_session(directory)` (resume newest nonempty session), but explicit file binding avoids choosing a different transcript. Both disabled discovery flags were verified directly in the exact pinned CLI source.

Undocumented model context/output numbers were removed from generated OMP configuration. Public Go model metadata returned HTTP 403 during this check; provider limits remain unverified and must be established during runtime qualification. R1's `RunPolicy.SCHEDULED` omits terminal-bound/global elicitation and memory controls. A native `build_ask_user_spec` is explicitly registered against a run-local clarification adapter; memory controls remain disabled. Plain assistant questions and the next user turn also remain supported.

### Structured clarification contract

The actual R1 question/output schema is reused: 1–3 questions, IDs `0` through `2`, each with 2–4 options. The worker emits `clarification_requested` with canonical UUID `request_id`, native `tool_call_id`, and questions `{id,question,header,options:[{label,description}]}`. The coordinator validates and atomically saves the request, ordered event and `waiting_for_input` status. It adds ISO8601 UTC `expires_at`, exactly 300 seconds after admission. This is an interaction expiry, not a research quota. A waiting turn occupies its actual admitted worker slot; browser disconnect does not stop it or accept a default.

The authenticated private answer endpoint accepts **only** `{answers:[{id,answer}]}` with exactly one nonblank answer per requested ID; freeform text is allowed. Question text comes from the saved request, never the browser. Question/header/option/description bounds are 4096/16/512/2048 characters, answers 8192 characters each, and complete control payloads at most 64 KiB of UTF-8 JSON. All strings and arrays use strict schemas; duplicate/unknown/missing question IDs are rejected. Canonical ID order determines the answer digest. Invalid input returns 422, foreign researcher/run/request 404, and conflicting replay or a closed unanswered request 409.

Response: `{id:run_uuid,status:run_status,clarification:{request_id,status,expires_at,answered_at,consumed_at}}`. Clarification statuses are `pending`, `accepted`, `consumed`, `expired`, `cancelled`, `interrupted`. Dates are ISO8601 UTC strings; unanswered/unconsumed timestamps are null. Answer acceptance commits before delivery. A same-answer retry returns the current durable receipt, preserving its original timestamps even after closure; a different answer conflicts. `accepted` never claims that a worker or model received the answer. The worker emits `clarification_consumed` only after validating the typed receipt; the coordinator validates the committed digest before atomically acknowledging it and returning the run to `running`. Receipt events contain no submitted answer text. Answers remain private coordinator state and authorized native tool results, never health/receipt metadata.

Controls travel only over the existing isolated worker's stdin, kept open after initial dispatch. Nonblocking bounded writes run outside the shared cancellation/process lock and retry committed controls until receipt. The worker has no coordinator DB access or HTTP credentials. Native SDK RPC/liveness and host-tool cancellation continue while a callback waits. Unknown controls, paths and arbitrary RPC commands are rejected. Expiry produces `clarification_closed` and an explicit empty/no-answer tool result, **never** R1's recommended-first-option fallback. It does not authorize any action. Existing workspace proposal approvals retain their separate ownership/version rules.

Cancellation closes active requests and still requires actual worker/cgroup confirmation for the run. Restart fences old workers before marking active runs and requests interrupted; saved requests/accepted answers remain replayable receipts, but no dead Python future or prompt is automatically resumed. Late consumed/request messages cannot reopen closed requests. An accepted-but-unconsumed answer after interruption is retained without claiming successful delivery. Events continue through the existing durable cursor/reconnect protocol.

Offline qualification: `python3 -m unittest bridge.tests.test_clarifications bridge.tests.test_clarification_channel -v` covers private API, durable commit/reopen/replay, restart/cancellation/expiry, input bounds, full-pipe nonblocking shutdown, and actual pinned SDK subprocess pause/answer/abort with synthetic OMP. The native R1 dispatcher roundtrip additionally requires the admitted private R1 pin; public CI skips only that method. These checks make no provider request and do not replace actual OMP/Go or target Linux acceptance.

Metadata clarification (2026-11-16): root verified the public [models.dev catalog](https://models.dev/api.json) `opencode-go/deepseek-v4.1-flash` record as 1,000,000 context / 384,000 output tokens, reasoning enabled, text/image input and text output. These describe provider limits, not application quotas. Exact pinned OMP custom-model schema marks both limit fields optional; per the narrow instruction to add them only if required, the frozen generated configuration still omits them. Actual runtime qualification remains pending.

Go client headers identify this application honestly as `kernscale-trading-hub/0.1 (omp-r1)` and bind `x-opencode-session` to the trusted conversation UUID on both OMP main requests and R1 reader requests, following the [official Go client requirements](https://opencode.ai/docs/console/go/#where-can-i-use-it). OMP configuration is per session under `.omp-agent`, explicitly selected with `PI_CODING_AGENT_DIR`; concurrent sessions do not share a mutable client-header configuration.
