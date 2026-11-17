# Existing native read-only HTTP source

`NativeTradingSource` reads existing saved research and native status from exactly
`https://trading.tail84e234.ts.net`. It does not provide portfolio, manual research,
R1 dispatch, paper controls or any native write. It does not replace the isolated
bridge or change its bearer-token contract.

Root-owned configuration must supply all three values before any GET:

- `trading.native_url`: the exact HTTPS origin above, without extra path, port,
  user information or trailing slash.
- `trading.native_account`: the exact admitted native account identifier, already
  trimmed, at most 200 bytes, with no control characters.
- `trading.native_account_fingerprint`: full lowercase SHA256 of that exact UTF-8
  account string. Both configured identity and every returned account must match.
  No Unicode/case folding is performed; an account spelling is not an alias.

The account identifier is operator configuration, never a browser query. The
source placeholder `global-paper` is not the live default account: it returned
404 in the 2026-11-17 read-only investigation. Record the actual admitted account
privately rather than copying a sample value. This adapter contains no native
account identifier or credentials.

Only these GETs are possible:

| Method | Existing route | Returned contract |
| --- | --- | --- |
| `status()` | `/api/operations?account=...` | `native-paper-research`, source observation ISO time, original failed/run states, worker flag, native/normalized timer next firing and metadata-response SHA256 |
| `reports()` | `/api/research?account=...&limit=500` | List of native UUIDs, status/card status, source start/completion epochs, creation ISO time, report availability and metadata-response SHA256 |
| `reportArtifact(UUID)` | `/api/research/{UUID}/report?account=...` | Account-verified native UUID, actual UTF-8 HTML bytes, `media_type=text/html` and SHA256 of those bytes |

Every request has redirects disabled, a two-second connection timeout and
five-second transfer/read timeouts. The body is streamed up to the existing
2,000,000-byte artifact limit and then closed. Unexpected status, content type,
encoding, account or malformed identity fails closed. Only an Accept header is
added: no Laravel cookies, bridge Authorization, workspace header or provider
credential is forwarded. Diagnostic/error bodies are not exposed in normalized
status. No new daemon, proxy, SSH tunnel, provider request or database export is
created by this source.

`reports()` retains failed runs. It caches only the verified metadata manifest
inside one service instance, keyed by account, avoiding repeated list fetches
within a sync. It does not download every report just to discover its hash.
The existing list API does not publish artifact/receipt digests, so
`report_sha256` and `receipt_sha256` are explicitly null. Its
`metadata_response_sha256` hashes actual API JSON bytes and is not a hash of the
native receipt file. `reportArtifact` first resolves its UUID in that scoped
manifest and fetches only an available selected report. The returned `sha256`
hashes actual HTML bytes; consumers may verify it against content, and compare
with a list digest only when a genuine list digest exists. Preserve HTML media
type and render safely; this reader never executes or converts HTML.

Read-only investigation on 2026-11-17 verified current-Mac HTTP200 access to the
operations/list/report routes via the existing Tailscale hostname. The native
service itself has a loopback/allowed-host boundary and no per-request member or
bearer authorization. Tailscale ACL, serve/funnel policy and future Ops service
identity access were not independently audited. Laravel membership and output
permissions remain mandatory; do not expose this legacy origin or its broader
mutating API as a replacement application backend.

Native account/position GET endpoints are deliberately excluded: their shared
connection helper executes schema/WAL setup and potential migrations, and marked
account/position reads can request provider quotes. The saved Ghostfolio
projection files exist through normal admitted SSH access, but no admitted
read-only HTTP portfolio projection was found. No financial metrics, prices,
performance series or source timestamps are invented here.

`TradingDataSync` now selects this source for agents/reports only when the private
bridge URL is unset and this source is explicitly configured. A configured
private bridge retains precedence. Portfolio always requires its separate
read-only bridge adapter; failure keeps the last observation and is reported.
Fetched HTML must match its actual artifact digest; a genuine list digest, when
present, must also match. New imported artifacts retain those hashes as
provenance and await owner review. Replays preserve original saved content.

The protected local environment and local Laravel scheduler are configured for
this source. Actual 2026-11-17 integration imported90 native run receipts and43
verified report artifacts, including43 failed-run metadata entries; no report
was automatically published. Native observations continue independently of
browser sessions. Counts are a dated observation, not fixed product fixtures.
Container image acceptance does not establish future Ops-to-Tailscale access or
isolated R1 admission.
