# Research session activity and read cursors

Session activity uses locally saved `research_events.id` values, not native remote event IDs, message counts, or guessed progress. Apply `2026_11_17_000010_research_read_cursors.php` before using the payload or endpoint. Existing sessions start with cursor zero.

## Session list

`GET /research` keeps each owned session's existing attributes and adds:

| Field | Meaning |
| --- | --- |
| `active_run` | `{id, status}` for the newest saved active run, or null |
| `last_event_id` | Highest locally saved event ID in this session, or zero |
| `last_seen_event_id` | Explicitly acknowledged cursor, initially zero |
| `unread_event_count` | Actual saved session events whose local IDs exceed the cursor |
| `has_unread` | Whether that count exceeds zero |

Active statuses are exactly `queued`, `dispatching`, `waiting`, `running`, `collecting`, and `cancel_requested`, matching the coordinator. Runs are ordered by `created_at` then `id`, descending; no UUID MAX aggregation is used. The activity service adds two batched queries regardless of session count and preserves caller ordering. All owned sessions receive activity, including nonselected sessions. Empty sessions have zero counts and null active run. Reading the page, selecting a session, or polling saved events never acknowledges activity.

## Explicit acknowledgement

`PATCH /research/sessions/{session}/read` accepts:

```json
{"through_event_id": 123}
```

The route requires authentication, verified email, approved membership, completed security enrollment, and the current researcher/owner role. Only the session's author may acknowledge it. An owner role grants no access to another researcher's session (404).

The cursor must be a nonnegative integer: zero or an actual saved local event ID belonging to that exact session. Events from other sessions, even another session owned by the same researcher, and nonexistent/future IDs receive a generic 422 validation error under `through_event_id`. Remote event IDs are not accepted as cursors unless they independently match an actual local ID in that session.

Successful JSON:

```json
{"session_id": "session-uuid", "last_seen_event_id": 123}
```

An atomic conditional update only advances the cursor. Stale/repeated acknowledgements and zero cannot move it backward. Acknowledgement does not change session `updated_at` or list ordering. The client should send the highest saved event ID it actually displayed; new events beyond that ID remain unread. No provider calls or financial state changes occur.
