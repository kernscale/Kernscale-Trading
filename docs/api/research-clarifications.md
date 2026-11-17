# Interactive research clarifications

`POST /research/runs/{run}/clarifications/{clarification}/answer` requires UUID route IDs and the usual authenticated, verified, approved, enrolled membership. Only a currently approved researcher/owner owning both the run and its private session can answer. Owner status grants no access to foreign sessions. The actor is freshly reloaded immediately before bridge I/O.

```json
{"answers":[{"id":"0","answer":"Use the current shared paper account."}]}
```

The body accepts only `answers`: a list of one to three `{id, answer}` objects, no extra fields. IDs are distinct strings `"0"`, `"1"`, `"2"`; each answer is a nonblank string of at most 8192 characters, and the encoded control JSON must not exceed 64 KiB. Freeform answers are supported. The bridge validates the exact question-ID set against its durable canonical request; the browser cannot supply questions, actor identity, workspace, provider data or alternate routes.

The proxy forwards to the fixed authenticated bridge path `/v1/researchers/{actual-user-id}/runs/{actual-run-id}/clarifications/{request-id}/answer`. Successful JSON responses contain only:

```json
{"id":"run-uuid","status":"running","clarification":{"request_id":"request-uuid","status":"accepted","expires_at":"2026-11-17T12:05:00Z","answered_at":"2026-11-17T12:01:00Z","consumed_at":null}}
```

Run/request identities, status enums and ISO timestamps are checked before accepting the receipt. Unknown fields and raw answers are not returned. A metadata-only terminal run receipt becomes local `collecting` until ordered event polling confirms the final result; an existing cancellation intent or confirmed terminal state is preserved. The returned run status reflects this local reconciliation state. `accepted` does not mean `consumed`: consumed status/timestamp come only from the bridge's explicit receipt. Normal forms redirect back with `Clarification response received.` Expected bridge absence/conflict/validation map to 404/409/422; malformed receipts return 502 and unavailability or ambiguous timeout returns 503 with a generic message. Retry the **same answers and request UUID** after timeout; the native bridge handles idempotence. The proxy never resubmits the original prompt or starts another research run.

`waiting_for_input` is an active single-flight state: it blocks another turn in that session, appears in session activity, is polled by `trading:reconcile`, and participates in durable cancellation on member revocation or researcher-role loss. A late waiting/input receipt cannot undo pending cancellation or a terminal run. Dispatch claims are unchanged. Questions and answers live durably in the bridge; Laravel adds no table/model or answer copy. Normal event polling mirrors ordered `clarification_requested`, `clarification_accepted`, `clarification_consumed`, and `clarification_closed` frames. The native pending deadline is 300 seconds; native expiry closes unanswered requests explicitly and never silently selects an option.

Focused local check: `php artisan test tests/Feature/ResearchClarificationsTest.php`. Provider delivery, native expiry, and frontend acceptance require their respective bridge/UI checks; PHP HTTP fakes do not claim these external outcomes.
