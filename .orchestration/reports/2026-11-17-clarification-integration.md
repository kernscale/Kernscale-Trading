# Structured research clarification

Implementation based on source `801c1ccf0cd3b56e6b2e4c08f0383753507e1d8e`.
Claude retains all frontend ownership. Existing execution/release approvals apply;
this work does not install or enable the target services.

## Acceptance checks

- Actual pinned SDK host-tool transport pauses for the researcher's structured
  answer and continues the same turn with the canonical R1 tool result.
- Coordinator SQLite commits a private question/request and answer receipt before
  delivery. Accepted and consumed are distinct; replay cannot change an answer.
- Question IDs and freeform answers are validated against the original request.
  Cross-researcher access, cancelled/expired requests and conflicting replies fail.
- A 300-second technical deadline matches the pinned R1 service default. Expiry
  returns an explicit no-answer result, never the recommended option.
- Waiting survives browser disconnect through ordered event replay. Cancellation
  still fences the actual worker/cgroup; restart interrupts rather than replaying
  prompts or pretending a dead process can resume its question.
- Laravel revalidates current approved membership and exact session/run ownership.
  Waiting remains active for single-flight, reconciliation and revocation.
- Workspace proposal decisions reject pending proposals seven days after original
  creation; replay does not extend expiry or repeat an already applied mutation.
- Scoped PHP/Python checks precede private source/image validation and a fresh
  actual Linux UID/cgroup check for the changed executor/store.

Clarification is user input, not permission to trade or execute arbitrary tools.
Memory tooling and unrelated R1 service globals remain outside this addition.

## Local source acceptance

The private Laravel answer route, all relevant waiting-state callers and native
R1 tool/typed control channel are implemented. No new Laravel schema is required;
the existing private coordinator SQLite recovery unit includes the new requests
and receipts. A native specification description is overridden only to describe
the actual no-default deadline behavior; its handler/schema/result are preserved.

Combined Laravel clarification/proposal checks passed 28 tests/164 assertions.
Level 7 PHPStan passed seven affected runtime files; changed-file Pint passed.
The combined pinned SDK plus exact local R1/actual loopback HTTP, attachment,
native-adapter, streaming, session and bridge selection passed 50 tests in 5.138s
with no skips. Fixture-only source checks and independent read-only lifecycle
review found no remaining blocker. UTF-8 byte-bound regression and inherited
misleading tool-description corrections passed before this combined run.

Receipts: `.orchestration/cache/clarification-php-integration.log`,
`clarification-phpstan.log`, `clarification-final-pint.log` and
`clarification-combined-sdk-http.log`. The new public CI modules are selected
explicitly. Its native-R1-specific method is unavailable when that separate
operator checkout is absent; the local combined run exercised it successfully.

Fresh private image/source CI for the corrected candidate remains pending.
The manual Linux fixture now persists a real coordinator question/wait state
before cancellation and requires its closure alongside UID checks and the death
of a detached descendant. It still replaces the OMP worker with a synthetic
fixture; it does not establish actual Go/R1/provider or production acceptance.

## Shared final-event correction and Linux evidence

Additional focused regressions reproduced premature completion through Stop and
idempotent dispatch replay, not only the new answer endpoint. Shared ingestion
now waits for an actual event collection or full output before settling a
metadata-only terminal receipt. A terminal-derived collection state may drain
events after cancellation intent; genuinely active late replies cannot undo it.
The never-admitted cancellation receipt now truthfully contains `events: []`;
no synthetic run/event rows are created, and GET run/events still returns 404.
The matched PHP fixtures and direct bridge protocol tests preserve this exception.
Root's final affected lifecycle/dispatch/clarification/artifact/proposal selection
passed 61 tests/348 assertions. The corrected bridge API plus real loopback HTTP
selection passed 25 tests in 0.712s. Scoped formatting and whitespace checks passed.
Logs: `clarification-final-php.log` and `clarification-final-api-http.log` in the
private orchestration cache.

Superseded image run 44169124572 was cancelled during publication after source
validation passed; it has no accepted image/runtime claim. The corrected candidate
will receive fresh image/source qualification.

Actual Linux manual run 44169130318 passed at bridge source
`70057523193f0751ead7519903acb6060fd122aa`. Both dedicated identities passed
all isolation checks, persisted `waiting_for_input`, closed their pending question
on cancellation, killed the detached descendant and ended with an empty cgroup
and confirmed cancelled executor. Cleanup verified. All protected source modules,
schemas/channel helpers and qualification fixtures remain byte-identical after
the separate PHP/private-HTTP correction. Receipts and component hashes are
referenced in [the qualification contract](../../docs/api/linux-isolation.md).

## Live deployment admission

Read-only normal-account SSH checks at 20:02:40 UTC succeeded on both hosts but
still found no Docker access or noninteractive sudo. Ops' helper inventory contains
only `loki-ingress.sh`; no app root, installed bridge unit, identity file or dedicated
bridge cgroup was observed. No helper or alternative privileged transport was used.
Receipt: `.orchestration/cache/target-admission-2026-11-17-resumed.json`.
Release permission is already recorded; admitted target access is still missing.
