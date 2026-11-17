# Backend capability integration

Frontend implementation remains with Claude. These contracts extend the existing
Laravel membership, security-enrollment and private-session gates.

| Capability | Endpoint or payload |
| --- | --- |
| Save completed interactive research | `POST /research/runs/{run}/output` with `title`, `kind`; canonical saved assistant text and evidence are selected by the server |
| Create a human revision from an output | `POST /outputs/{output}/draft` with `title`, `kind`, reviewed source `version`, `idempotency_key`, optional owned `session_id` |
| Read actual earlier revisions | Author-only `GET /outputs/{output}/revisions` |
| Discuss a published artifact | Existing `POST /community` also accepts paired `output_id` and `output_version`; titles never resolve artifact identity |
| Acknowledge displayed session activity | `PATCH /research/sessions/{session}/read` with `through_event_id` from a saved **local** event ID |
| Answer a paused research question | `POST /research/runs/{run}/clarifications/{request}/answer` with complete `answers: [{id, answer}]`; answers never grant tool execution authority |
| List/upload evidence | `GET`/multipart `POST /research/sessions/{session}/attachments` |
| Download original private evidence | `GET /research/sessions/{session}/attachments/{attachment}`; existing session ownership is mandatory |
| Include evidence in research | Existing run POST accepts `attachment_ids`; server resolves immutable receipt/text snapshots |
| Manage an existing member | Confirmed-owner `PATCH /admin/members/{user}` with `role`, `membership_status`; existing DELETE uses the same protected transaction |

Session list rows now carry `active_run`, `last_event_id`, `last_seen_event_id`,
`unread_event_count` and `has_unread`. Counts describe saved events, not assistant
messages. GET does not mark activity read. Acknowledge only after displaying it.
The active session and workspace response expose `outputs` metadata containing
the same canonical IDs/revisions used by the Outputs page. Saved/derived artifact
responses return that ID; normal forms redirect to `/outputs?output=<id>`.

PDF, TXT, Markdown and CSV originals are stored privately after admission.
Receipts contain `id`, `name`, `media_type`, `size`, `sha256`; extracted text and
storage paths are excluded. Input bounds are technical: 10 MiB original, 2 MiB
extracted text per file/combined run and 30-second parsing. Oversized/unreadable
input is rejected, not silently truncated. Source-backed drafts distinguish
admitted attachments from actual read references and never grant public access
to the original file.

Output review still requires the exact displayed `version`. The existing
frontend review form must send it. Replays return existing artifacts; changed
creation metadata or stale first-save source versions conflict. Session/private
artifact ownership is not bypassed by an owner role. Role/status removal records
durable research cancellation intent without pretending the worker has stopped.

`waiting_for_input` remains an active run. Render its ordered
`clarification_requested` event using the original question IDs; submit one answer
per question. A preselected recommendation is not an answer. The server's
300-second deadline closes an unanswered question without choosing an option.
An accepted answer is durable; consumption requires the separate worker receipt.
Pending workspace proposals expose `expires_at`, seven days after their original
creation; expired decisions return 409 and need a new proposal.

The local scheduler now reads actual scheduled status/reports from the configured
private native HTTP API. Imported report originals await owner review and remain
immutable; human revisions use the derived-draft endpoint. The isolated R1 bridge
retains precedence when configured. Portfolio data still requires its distinct
read-only projection connection; this source does not call native financial
endpoints that can initialize schemas or fetch quotes.

Detailed contracts: [artifacts](research-artifacts.md),
[attachments](research-attachments.md), [activity](research-activity.md),
[revision/discussion links](output-history-community.md),
[member management](member-management.md), [native source](native-source.md),
[review versions](output-review.md), [local PDF tooling](local-pdf.md),
[clarification lifecycle](research-clarifications.md) and
[proposal expiry](workspace-proposals.md).
