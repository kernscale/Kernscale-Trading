# Structured research clarification acceptance

Backend source `8e9f10cd38fcc5819f005c12c45224ae6f9db02d` is published in the
private owning repository. Claude retains frontend ownership; no `resources/**`
changes were included. Existing execution/release approvals apply. This is source
and disposable-runtime acceptance, not a target installation or domain cutover.

## Result

The native R1 `ask_user` tool now pauses a turn for one to three structured
questions. Private coordinator SQLite commits the question and answer before typed
worker delivery; accepted and consumed are separate receipts. Question IDs,
freeform answers, scope and replay are validated. Another researcher cannot answer;
a changed replay conflicts. The 300-second native deadline returns an explicit
no-answer result rather than choosing an option. Cancellation/restart closes
requests without reopening calls or replaying prompts. Waiting uses an actual
worker slot; it introduces no research-count or spending quota.

Laravel revalidates current membership and exact private run/session ownership.
`waiting_for_input` participates in single-flight, event collection, reconciliation
and revocation. No new Laravel table is needed; existing saved events mirror the
private bridge receipts. Clarification does not grant trading/tool authority.
Pending workspace proposal decisions expire seven days after original creation;
already-applied same-decision replay remains idempotent.

A related shared-ingestion regression was reproduced and corrected: Stop,
dispatch replay and answer acknowledgements cannot seal partial assistant text
from a status-only terminal reply. Final events are collected first. True
never-admitted cancellation receipts declare `events: []`, with no synthetic
run/event rows; GET run/events still returns 404.

## Verification

- Root final local PHP selection: 61 tests/348 assertions. Scoped level 7 PHPStan,
  changed-file Pint, Python/workflow/shell syntax, source-size and whitespace passed.
- Actual pinned SDK plus exact local R1, real loopback HTTP, attachments, native
  adapter, stream, session and bridge selection: 50 tests/5.138s without skips.
  After the narrow cancellation correction, 25 API/real-HTTP tests passed/0.712s.
- [Private run 44170647416](https://github.com/kernscale/Kernscale-Trading/actions/runs/44170647416)
  passed 196 selected PostgreSQL tests/1,237 assertions, candidate types, runtime
  source and Capitalio-reference checks. SDK/API CI ran 69 tests: 68 passed and one
  native-R1 method was skipped because that separate operator checkout is absent.
  The local pinned R1 run exercised that method successfully.
- [Linux run 44169130318](https://github.com/kernscale/Kernscale-Trading/actions/runs/44169130318)
  qualified bridge source `70057523193f0751ead7519903acb6060fd122aa`. Both dedicated
  identities persisted an actual coordinator waiting question, closed it on
  cancellation, killed a detached descendant, emptied the cgroup and committed
  cancelled. UID/GID/environment/private-file guards and cleanup all passed.
  Every source/helper/fixture loaded by that qualification is byte-identical in
  the final candidate. The later correction changes only PHP and the private
  cancellation HTTP response/tests; no kernel-qualified component changed.

The verified private Linux/amd64 image is
`ghcr.io/kernscale/kernscale-trading@sha256:e45ab05aff65c2c6fd9334ed90fd7b221054d4360b95cd8d77877843926db50e`.
Downloaded image checksums, exact source identity, private package visibility and
runtime receipts matched. Actual container checks verified 503 before migrations,
200 after, read-only UID 10001, Redis, PDF extraction/private storage, Horizon,
scheduler definitions without starting producers, login plus 23 assets, Chrome
and labelled resource cleanup. `ciRuntimeAccepted` is true;
`productionRuntimeAccepted` is false. Superseded image run 44169124572 was cancelled
during its build and has no accepted image/runtime claim.

The [durable acceptance receipt](2026-11-17-clarification-acceptance.json) contains
actual runtime/Linux fields and component hashes. Detailed logs are under
`.orchestration/cache/clarification-*`, `clarification-final-runtime/` and
`linux-isolation-44169130318/`.

## Local recovery and remaining acceptance

The original preview ports/processes and scheduler were confirmed absent before
restarting Laravel 8007, Vite 5177 and the local application scheduler. All three
HTTP checks returned 200. A stale `trading:sync` overlap mutex was removed through
Laravel's exact event helper only after no sync process was found. No other mutex,
account or research data was reset. At 20:44:35 UTC the scheduler had refreshed
status at 20:44:01 and reports at 20:44:02: 93 native receipts and 47 saved originals.
This is observed feed recovery, not proof that every native research run succeeded.

Read-only normal SSH checks at 20:02:40 UTC still found no Docker/noninteractive
sudo access or app-scoped deployment interface on Ops/Agents2. Ops listed only
`loki-ingress.sh`; no app root, installed bridge unit, identity file or dedicated
bridge cgroup was observed. No alternative privileged transport was used.
Receipt: `.orchestration/cache/target-admission-2026-11-17-resumed.json`.
Release permission is recorded; target admission remains missing.

Actual installed Go/R1/provider and systemd qualification, native portfolio
projection/reconciliation, canonical-origin authentication/mail, measured off-host
restore, final frontend wiring and domain promotion remain required. Broad suites
and production deployment were not run. The full-system goal is not complete.

Frontend contracts: [clarifications](../../docs/api/research-clarifications.md),
[proposal expiry](../../docs/api/workspace-proposals.md),
[final-event/cancellation protocol](../../docs/api/research-cancellation.md) and
[complete wiring map](../../docs/api/capability-integration.md).
