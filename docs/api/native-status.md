# Native research and service status

The bridge adds read-only native observations. It never starts, stops, enables,
disables or restarts a unit, calls a provider or edits the native ledger/marker.
Frontend ownership remains separate.

`TRADING_SCHEDULE_PATH` is trusted operator configuration for the actual installed
user timer file, for example `tradingagents-paper-research.timer`. Its schedule
text/hash remain available when live observation is unavailable. A valid literal
timer basename is queried with `systemctl --user show`; its observed `Unit`
property must be a valid literal `.service` before a second query. No unit name
comes from an HTTP request. Only these properties are requested:
`ActiveState`, `SubState`, `UnitFileState`, `Result`, `ExecMainStatus`, `Unit` and
`NextElapseUSecRealtime`. Both subprocesses use argument lists, `shell=False`, a
two-second timeout, UTC/C locale and a limited transport environment. Commands,
environment variables, credentials and raw errors are not returned.

`service_status` preserves existing keys and adds `worker` metadata. Valid live
metadata makes `schedule.state` and `worker.state` `observed`. Otherwise their
state remains unverified and unknown values remain null. A timer can be observed
while its service is unavailable. Installed inactive/failed services are observed
as `worker_active=false`; active/running and oneshot activating/start states are
true. Unsupported transitional states remain null. Unit-file state is reported
as observed, without treating missing metadata as disabled. A recognized UTC next
firing is converted to ISO8601; missing/malformed times remain null.

Top-level `enabled` mirrors the native research marker's file-presence semantics:
an empty regular `enabled` file is enabled. Missing marker is false only when the
research root is readable; unsafe/unreadable inputs are unknown. This marker,
timer enabled state and process state are independent observations.

Native receipt `status` is preserved exactly in saved reports and `latest_run`.
The additional `research_outcome` never comes from unit exit status or file mtime:

- `failed` for a failed native receipt or an error without completed research.
- `execution_failed` for an errored `researched` receipt with complete card/report.
- `in_progress`, `interrupted`, `incomplete` and `execution_pending` reflect the
  native lifecycle/card rather than claiming completion.
- `complete` requires native status complete, a complete structured card and a
  readable saved report; otherwise completion is `unverified`. A native complete
  scout/wait result without generated research therefore remains unverified.

The sanitized live sample observed on 2026-11-17 had five failed receipts and one
complete receipt between 00:10 and 10:10 UTC. These shapes are regression fixtures,
not a current production-health assertion. An idle successful oneshot service
with `ExecMainStatus=0` does not turn failed research into successful research.
Reports and their original status/checksums remain accessible if systemd queries
fail. Live timer metadata is only available when the installed bridge identity
can reach the owning native user's bus; container CI has no native host admission.

## Native identity and protected-path admission

The root bridge coordinator must set trusted operator configuration
`TRADING_NATIVE_SERVICE_USER=jonas` (or the actually admitted native Linux user).
Only a validated literal Linux username is accepted; it is never taken from an
HTTP request. Root without this identity, root targeting root, invalid identities
and nonroot identities targeting another user produce unknown process/schedule
observations without executing `systemctl`. Root uses the explicit local transport
`systemctl --user --machine=jonas@.host show ...`; root's own user bus and provider
credentials are not inherited. A nonroot bridge may observe only its current
user, with an optional configured username required to match that identity.

The deployment service's `ProtectHome=true` remains intact. An administrator must
admit the specific read-only native timer/report/ledger paths in the protected
namespace and qualify the chosen local user transport. Merely configuring the
native username does not expose those paths or establish bus connectivity. No
whole-home exposure, bus mount expansion, sudo route or service-policy change is
implemented here. Inside an unadmitted root/container service, the correct result
remains unverified/null with saved receipt status preserved whenever its admitted
report path is readable.
