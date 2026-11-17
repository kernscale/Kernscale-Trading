# Durable research cancellation

`POST /v1/researchers/{researcher}/runs/{run_id}/cancel` requires the existing
service authentication and `X-Workspace-ID: kernscale-trading`. The researcher
must be a positive integer and the run ID a UUID. Researcher identity comes from
the trusted application, never browser input to this private API.

## Cancellation before admission

If no run has been admitted, the bridge commits a separate SQLite cancellation
receipt scoped to that researcher and run UUID, then responds:

```json
{"id": "the-run-uuid", "status": "cancelled"}
```

This means admission has been durably prevented. No worker was started, session
bound, request stored, process completion asserted, or synthetic run event
created. Repeating the same POST returns the same receipt, including after
coordinator restart. GET run and GET events remain 404 because no run exists;
repeat POST is the idempotent receipt query.

A later PUT for the same researcher/run UUID returns 409 and never submits an
executor job. The UUID is terminal regardless of the later payload. A new run
UUID is required for new work. The receipt does not reserve a session UUID.
Another researcher receives 404 on both cancel and PUT for that run UUID, with
no mutation or ownership transfer.

Admission and cancellation both use `BEGIN IMMEDIATE` on the same database.
Whichever transaction acquires the write lock first decides whether a real run
exists. If cancellation commits first, admission cannot occur. If admission
commits first, the ordinary admitted-run cancellation protocol applies. A failed
commit cannot produce a successful receipt response. Receipts have no automatic
expiry or purge.

The coordinator SQLite database is persistent recovery state, including these
receipts and admitted-run replay identities. The Operations recovery candidate
captures it through a separate native online SQLite backup as
`bridge-coordinator.sqlite`; a complete recovery unit requires
`agents2-bridge-coordinator`. Restore and reconcile that database with the other
recovery components while writers remain fenced, before accepting new research.
Researcher transcript files alone do not preserve the cancellation barrier.

## Cancellation after admission

For an admitted nonterminal run the bridge durably sets `cancel_requested` and
asks the executor to cancel. Repeated requests remain idempotent and can repeat
the executor cancellation signal. The response does not claim the worker has
stopped. Existing executor confirmation/fencing must produce the final state.
Existing terminal runs return their terminal status. Foreign researcher access
continues to return 404 without changing the run or creating a receipt.

Payload digest replay checks, session single-flight admission, restart
reconciliation of admitted runs, and native session isolation retain their
existing behavior. Pre-admission receipts remain separate from reconciliation
and from the authoritative paper ledger.

## Offline verification

`python3 -m unittest bridge.tests.test_bridge -v` exercises actual FastAPI
TestClient requests and SQLite with deterministic FakeExecutor. Regressions
cover both transaction orders, pre-admission idempotency and restart persistence,
late PUT rejection without executor submission, foreign and invalid identities,
no fabricated sessions/events, and admitted cancellation remaining pending.
These checks make no provider or host requests.
