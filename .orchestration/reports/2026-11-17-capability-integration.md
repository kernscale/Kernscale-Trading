# Backend capability integration

User resumed implementation after the target-access audit and retained Claude's
frontend ownership. Baseline source `a1712b093df4a0a7c190eb88c1b5fb42eeb48bec`.
Backend-only changes preserve the native paper ledger/schedules and concurrent
`resources/**` edits. Local schema changes are additive and were applied after a
private SQLite online recovery copy passed integrity checking.

## Implemented

- Canonical private artifacts from completed interactive runs, with actual saved
  assistant text, evidence/event references, source links and immutable hashes.
- Editable private drafts derived from visible native/published/owned outputs,
  preserving source revision/hash identity and sealed originals.
- Author-only revision history and discussions linked to exact published output
  IDs/versions rather than titles.
- Batched actual session activity and monotonic private read cursors.
- Private PDF/text/Markdown/CSV attachment receipts, scoped downloads, immutable
  run snapshots and typed OMP attachment-list/read tools. Actual read references
  are distinct from merely admitted files; publication does not share originals.
- Owner role/status updates and credential-preserving membership restoration.
  Existing DELETE revocation shares fresh-authority checks, audit transaction and
  durable cancellation intent with PATCH.
- Existing private native GET API adapter for scheduled status/reports, with
  pinned origin/account, no credentials forwarded, strict bounded bodies and
  actual artifact hashes. No native financial GET is called.

## Local observations and checks

Actual local import: 90 scheduled-run receipts, 43 failed-run metadata entries and 43
saved HTML artifacts with matching content hashes. Every imported artifact was
`in_review` at capture; no automatic publication occurred. The existing portfolio
observation remains unavailable. Local Laravel scheduler was started after no
existing scheduler was found; observed minute executions and refreshed snapshots
must remain distinct from native producer health or real R1 execution.

Combined explicit affected PHP selection passed 108 tests/701 assertions; serial
level 7 PHPStan passed the 24 affected runtime files. Following derivative
integration, 40 affected artifact/activity/workspace/publication tests passed 328
assertions. The project-local test environment gained only declared FastAPI/
Uvicorn dependencies (no global install); actual pinned SDK/R1 plus attachment,
streaming, session and HTTP/API selection passed 42 tests/4.808s. PDFKit local
adapter compiled with installed Xcode and passed 10 synthetic input/failure checks.
Attachment tests exercised real extraction, private routes and an actual 30-second
timeout. No provider request was made by these fixtures.

Logs: `.orchestration/cache/capability-integration-{php,phpstan,sdk}.log`,
`capability-final-artifacts.log`, `live-native-sync.log`, `local-scheduler.log`,
`pdfkit-selfcheck/receipt.json` and feature-lane receipts. Private recovery copies
and the project-private native extractor remain ignored/local, not source assets.

## Release validation and remaining acceptance

The candidate needs fresh PostgreSQL source CI and an immutable image check for
the new schema, Linux Poppler, 10 MiB/12 MiB PHP upload settings, actual private PDF
storage/extraction/snapshot, readiness and existing worker/runtime invariants.
The disposable runtime check now exercises that path as nonroot UID 10001.

Actual isolated Go/R1 execution and final Ops/Agents2 installation still require
target admission. Native financial projection, canonical-origin passkey/mail,
complete off-host restore and Claude frontend wiring remain separate acceptance.
The full-system goal is not complete. [Frontend wiring map](../../docs/api/capability-integration.md).
