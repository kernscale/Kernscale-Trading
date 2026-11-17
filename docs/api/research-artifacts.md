# Canonical artifacts from completed research

`POST /research/runs/{run}/output` accepts `{title, kind}`, where `kind` is
`research`, `strategy`, or `document`. It uses the existing authenticated,
verified, approved-member and security-enrolled route group. Only the run's own
researcher or owner-role author can save it; owner does not grant access to
another researcher's session. Downgraded members receive 403, foreign runs 404,
and incomplete runs or unavailable/blank canonical assistant text 409.

The server reads the saved assistant ResearchMessage for that exact run and
session. It never builds the artifact from partial token events, browser content,
browser citations or prompt text. Client content/sources/provenance/run claims
are prohibited. No model/provider call occurs.

## Response and replay

First JSON request returns 201; replay with the same original title/kind returns
200, with the same underlying artifact ID:

```json
{
  "created": true,
  "output": {
    "id": "artifact-uuid",
    "research_run_id": "run-uuid",
    "research_session_id": "session-uuid",
    "title": "Report",
    "kind": "research",
    "status": "draft",
    "version": 1,
    "content": "Canonical saved assistant text",
    "original_checksum": "sha256-of-original-content",
    "sources": [],
    "provenance": {}
  }
}
```

Normal form submissions redirect to `/outputs?output=<artifact-id>`. Research
and Outputs should reference this ID, rather than copying another artifact.
Root adds session output references separately to the workspace/session payload.

A nullable unique run foreign key and transaction locking on the run enforce
one canonical artifact per run. The run UUID itself is the replay key; no new
idempotency key is needed. Different original title/kind returns 409. Later human
draft edits do not change replay identity: repeat the original creation metadata
to retrieve the artifact's current revision. The response's `created` is false
on replay. A created-only audit entry records the output and run IDs.

## Immutable original provenance

The initial content SHA-256 and provenance snapshot survive normal draft
revisions/review. The provenance contains schema_version, source
`saved_interactive_research`, run/session/assistant-message IDs, content_sha256,
origin_title/origin_kind, and evidence references from actual saved `record`
events. Each evidence reference contains its local and remote event IDs,
evidence ID, selected tool name, valid input evidence IDs, and
event_payload_sha256. The latter hashes the saved structured event payload
encoded with PHP JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE; it is not a
claim about original provider wire bytes. Repeated record events retain their
distinct event references.

Sources come only from saved `source` events or record payload
sources/pages/results. They preserve actual safe URL, optional title, positive
integer citation index, originating event IDs, and evidence ID when available.
URL/citation pairs are deduplicated. No citation index or evidence link is
invented. Unsafe schemes, embedded URL credentials, control characters, known
private/reserved literal IPs and local hostnames are excluded. No DNS lookup or
source fetch occurs; hostname provenance is not a network safety attestation.

The snapshot excludes raw prompts, tool arguments/results, headers, API keys,
private debug fields, and arbitrary provenance maps. The canonical assistant
answer remains exactly as saved. Publication retains the existing human review
flow; the operation creates only a private draft. Nullable link/provenance fields
preserve existing manual, legacy and immutable native outputs.

## Private attachment provenance

New artifacts also snapshot two distinct provenance lists:

- `admitted_attachments`: metadata from the run's immutable attachment snapshot,
  whether or not a tool read it. Each item has `id`, `name`, `media_type`, `size`,
  `sha256` (original file bytes), and `extracted_text_sha256` (the exact admitted
  UTF-8 text bytes). Text, storage keys/paths and arbitrary snapshot fields are
  excluded. Missing attachment snapshots produce an empty list.
- `attachment_references`: actual saved `attachment_reference` events, accepted
  only when ID and original SHA-256 match an admitted item. If an event also
  supplies an extracted-text digest, it must match. Each reference contains the
  admitted metadata plus saved local `event_id`, `remote_event_id`, and
  `event_payload_sha256` using the same JSON encoding as evidence events above.
  Unknown IDs, wrong digests and malformed receipts are omitted. Event metadata
  cannot override the admitted filename/type/size. Repeated reads retain their
  distinct event references; mere admission does not claim actual tool use.

Attachment references are separate from HTTP-only `sources`; no public URL or
download entitlement is invented. Publishing an output can expose its safe
provenance metadata under the existing publication policy, but does not authorize
any reader or owner to download another researcher's private original file.
The original session/attachment authorization still applies. Both provenance
lists are immutable after artifact creation and remain unchanged through human
draft revisions and idempotent replay, even if later run data changes. Existing
artifacts are not retroactively rewritten or backfilled.

## Editable drafts derived from visible outputs

`POST /outputs/{output}/draft` accepts:

```json
{
  "title": "Human working draft",
  "kind": "strategy",
  "version": 1,
  "idempotency_key": "opaque-request-key",
  "session_id": null
}
```

`kind` is research/strategy/document, `version` is the reviewed source version,
and idempotency_key is required with at most 100 characters. Optional session_id
must identify the requester's own session. Omission leaves the draft unbound;
the source's private session is never inherited. Browser content, sources and
provenance are prohibited.

Only approved researchers/owners can derive a draft. Source visibility uses the
existing `ResearchOutput::visibleTo` policy exactly: published outputs and one's
own outputs; owners additionally see in-review/native imported outputs. An owner
cannot clone another researcher's private draft. Foreign/invisible source or
target session returns 404, downgraded member 403, and stale reviewed version on
first creation 409.

The server copies the source's current saved content and sources into a new
private draft authored by the requester. `external_id` and `research_run_id` are
null, so the normal human revision/review flow can edit the draft. The original
source—including sealed native/legacy receipts—remains unchanged.
source_output_id/source_output_version link the derivative to its reviewed source.
Its original_checksum is the SHA-256 of the copied body. Immutable provenance
also records source ID/version, source_content_sha256 and the source's existing
original checksum. For the requester's own source only, selected evidence and
attachment metadata/references are copied through a field/type whitelist under
inherited_source_references. Raw source provenance maps, prompts, private session
bindings, file/storage paths and debug fields are not copied. Foreign visible
sources carry an empty inherited-source-reference map; visible content/sources
are copied without expanding access to original research sessions/files.

JSON responds `{created, output}` with 201 on first creation or 200 on replay;
the explicit output object includes its actual ID, source link/version, current
content/sources/status/version, checksum, provenance and optional target session.
Normal forms redirect to `/outputs?output=<draft-id>`. The key is scoped to the
requester by a unique `(user_id, derivation_key)` constraint. Matching source,
reviewed version, original title/kind and explicit session returns the same draft
even after human edits or a later source revision. Different creation metadata
under the same key returns 409. Replay revalidates current source visibility and
target-session ownership. A different key requires reviewing the current source
version. A created-only audit entry records `output.derived`.

This operation neither edits published originals nor submits/publishes the new
draft automatically, and does not grant private attachment download rights.

## Focused checks

`php artisan test tests/Feature/ResearchArtifactsTest.php` covers canonical text,
saved evidence hashes/citation links, private field exclusion, replay/conflicts,
foreign and downgraded users, incomplete/missing text, revision retention,
existing security gates, manual/native compatibility, admitted-versus-read
attachments, forged reference rejection, and private download denial after
publication. No UI, provider or
production-host acceptance is claimed by these checks.

`php artisan test tests/Feature/DerivedOutputsTest.php` verifies native source
immutability, editable derived revisions, reviewed-version conflicts, scoped
replay/metadata conflicts, published and private visibility, explicit own-session
binding, and whitelisted inherited provenance.
