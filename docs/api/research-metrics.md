# Research metrics events

These additive events use the existing private run event envelope:
`{id, type, payload, created_at}`. Existing Laravel ingestion persists the payload
as research event JSON. Each event belongs to its authorized run and researcher;
native IDs are correlation identifiers, not access credentials.

## Assistant usage: `usage`

One event is emitted for each native assistant `message_end`, from that message's
actual normalized `usage` dictionary. The payload is:

```json
{
  "source": "omp_normalized",
  "native_session_id": "opaque native ID or null",
  "message_id": "opaque message ID or null",
  "response_id": "opaque response ID or null",
  "model": "runtime model ID or null",
  "provider": "runtime provider ID or null",
  "available": true,
  "counts": {"input": 12, "output": 6, "cacheRead": 3, "cacheWrite": 0, "totalTokens": 21},
  "cost": null
}
```

Only `input`, `output`, `cacheRead`, `cacheWrite`, `totalTokens`, and
`reasoningTokens` are admitted, when present as finite nonnegative numbers.
Invalid counters are omitted. Missing, empty, or entirely zero/default usage
produces `available: false` and `counts: null`. The normalized SDK can supply
default zeros when the upstream provider reports no usage; these are unknown,
not evidence of a free request. A positive dictionary may still contain SDK
default zero counters. Do not interpret individual zeros as independently
verified provider reports. Cost always remains null; no catalog cost or billing
claim is forwarded.

Counts describe one assistant response, not a session total. Use event IDs for
replay deduplication. Do not add `totalTokens` to its component counters, or add
reasoning tokens to output without knowing the provider's accounting semantics.
No session-statistics aggregate is emitted or added to these counts.

## Current context: `context`

Only the coordinator thread queries native `get_state().context_usage`; event
callbacks never issue RPC commands. Payload fields are:

| Field | Meaning |
| --- | --- |
| `source` | Always `runtime_estimate` |
| `native_session_id`, `model`, `provider` | Available runtime correlation metadata, otherwise null |
| `available` | Whether a finite nonnegative context token estimate exists |
| `tokens` | Actual runtime estimate, otherwise null |
| `context_window` | Positive finite runtime capacity, otherwise null |
| `percent` | Actual runtime percentage only when tokens and positive capacity exist; otherwise null |
| `capacity_source` | Always `runtime_model_metadata` |
| `capacity_verified` | Always false |
| `final` | True for the final snapshot of a successfully completed turn |

The runtime may combine a provider token anchor with local estimates. Its RPC
schema does not expose that distinction, so this contract does not claim exact
provider context consumption. It never supplies an inferred Go context limit or
computes a missing percentage. Capacity remains unverified runtime metadata.
Initial and changed snapshots are emitted, plus one final snapshot. Identical
liveness polls produce no additional events. Missing data stays null, not zero.

## Auxiliary reader: `r1_reader_usage`

The admitted R1 provider `metered()` and `captured()` scopes surround each native
host tool dispatch. If the reader records a call, a separate payload is emitted:

```json
{
  "source": "r1_reader_reported",
  "native_session_id": "opaque native ID or null",
  "tool_call_id": "native tool call ID",
  "profile": "reader profile name or null",
  "model": "reader model ID or null",
  "calls": 1,
  "available": true,
  "counts": {"prompt": 9, "completion": 4},
  "cost": null
}
```

The native meter sums reader calls within that tool callback. No event is emitted
when no reader call was recorded. Missing or all-zero endpoint counts produce
`available: false`, `counts: null`, even when `calls` is known. R1 itself normalizes
an unreported counterpart to zero; individual zeros are not billing evidence.
Cancelled calls that report no native usage cannot supply counts. These events
are separate from OMP assistant usage and must not be presented as OMP context
occupancy or folded into its response counters.

## Privacy and verification limits

Explicit field selection excludes system prompts, full messages/state/model
objects, tool dumps, private thinking, headers, API keys, provider URLs, raw debug
responses, and cost structures. Metrics contain no synthetic financial data.
The fixtures exercise the pinned public SDK transport and native R1 usage scopes
with synthetic counters; no real provider request or billing verification is
part of these checks. Linux isolation and real provider qualification remain
separate release acceptance requirements.
