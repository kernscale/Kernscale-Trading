# Backend runtime validation

Current accepted application-image candidate: `27aff1b79afc4672eaecc386ffb0faa72ffff67b`. [Private workflow44084167843](https://github.com/kernscale/Kernscale-Trading/actions/runs/44084167843) completed source validation, image publication and disposable Linux runtime checks successfully. The selected PostgreSQL paths passed **113 tests /529 assertions**; the pinned SDK streaming/session/metrics/native-status selection passed **33 tests** on Linux. Local native R1 adapter checks are recorded below. Claude's concurrent frontend edits are excluded from this candidate.

Current application image: `ghcr.io/kernscale/kernscale-trading@sha256:aa220ff84be5dac7f63c048e9434c346a6b5c12137529efa56994e5566862359`. Downloaded image evidence checksums matched. Actual pulled image configuration, private package and exact source labels were verified. Runtime receipt verifies pre-migration503, migrated200, read-only UID10001, Laravel Redis roundtrip, running Horizon, scheduler loading without starting,23 built assets, Chrome login rendering and labelled-resource cleanup. It explicitly records **`ciRuntimeAccepted:true` / `productionRuntimeAccepted:false`**.

Receipts: `.orchestration/cache/backend-runtime-44084167843/{image,runtime}/` and `backend-runtime-44084167843.log`. The Python bridge is separately installed from this exact private source commit; its Linux protocol fixtures do not prove actual protected-service user transport, cgroup isolation or a provider turn on Agents2.

## Previous baseline

Checked application source: `ec9e4cc8d5eb056be0ac255e1c6765963e7ce710`. [Private workflow44076111678](https://github.com/kernscale/Kernscale-Trading/actions/runs/44076111678) completed validation, publication and disposable Linux runtime jobs successfully. This proves the recorded CI scope, not production acceptance. Claude owns frontend implementation; these backend commits preserve its concurrent working-tree changes.

Immutable application image: `ghcr.io/kernscale/kernscale-trading@sha256:d1ac80ca71f0360905ccb183569dcdb5ff9920c2522822d046ea4b0240e04164`.

## Observed checks

- Selected PostgreSQL17 feature paths: **110 tests /424 assertions passed**. This is the explicit affected selection in `tests.yml`, not a whole-repository suite.
- Production Compose/source boundary tests: three passed. Immutable image verifier: five passed. CI safety/real Compose-merge checks: seven passed. Twelve-file original Capitalio source guard passed.
- Exact declared OMP Python SDK was installed from its pinned Git revision in CI. Seven streaming/session-path tests passed on Linux. Local actual pinned SDK plus R1 checks passed13tests; a separate **62.042-second** responsive offline transport run verified the long-turn fix. No provider call was made by those fixtures.
- Actual disposable image returned503 before migrations and200 with the exact commit/digest after native PostgreSQL migrations. Database sessions do not prevent readiness reporting; a missing session table still fails readiness.
- Actual app process used UID10001 with a read-only root filesystem. Authenticated Redis write/read/delete passed through Laravel; Horizon started and reported running. Scheduler definitions loaded without starting a scheduled producer.
- Production login and23 built assets returned200. Preinstalled Chrome rendered the actual login/passkey controls. This guest flow does not prove canonical-origin physical authenticator acceptance.
- Cleanup verified removal of only the CI run's labelled resources. Image evidence checksums all matched after download. Receipts retain `ciRuntimeAccepted:true` and **`productionRuntimeAccepted:false`**.

Private receipts/logs: `.orchestration/cache/backend-runtime-44076111678/` and `backend-runtime-44076111678.log`. An earlier runtime attempt44072004812 correctly failed after migrations because the fixture SMTP host used a forbidden placeholder domain. The fixture now uses an internal synthetic hostname; production mail validation was not weakened and no mail was sent.

## Correctness changes included

Readiness is stateless and explicitly verifies configured database-session storage. Output revisions accept normalized numeric form versions; edit/submit checks require the current researcher/owner role. Review decisions and their audit entries commit under a row lock against the exact reviewed revision. [Review callers must supply `version`](../../docs/api/output-review.md).

Research uses bounded SDK history and callbacks instead of the SDK's60-second whole-turn collector. Native session bindings are persisted atomically before prompts, allowing a materialized first turn to resume after cancellation bypasses finalization. Tool start/progress/end events share call IDs; R1 result fields remain compatible. This is source/protocol evidence; real isolated Linux provider execution remains unqualified.

## Native observation, separate from CI

Read-only pinned Agents2 SSH observation at **2026-11-17 11:53:39 UTC**, exit0, found six recent saved native receipts: five failed and one complete. The latest completed at10:23:04UTC with complete card/report/execution receipt. Prior failures must remain visible; successful service exits are not research success.

Native paper ledger query used SQLite `mode=ro`/`query_only`: one account, one open position, one filled order, one risk-settings row; latest audit10:11:11UTC. No marks are stored in that SQLite ledger, so DB modification time does not prove fresh market valuation. No paper state, risk limits or schedules were changed by Codex.

Observed native revisions: TradingAgents `7e2522e7adab1b45af1ef0de8668fa8b42b86896`; Trading-CLI `ef5d9ba5a692acdee53cc3348926a97f9f0339cc`; native Trading-R1 `748ff7fcd9b9ed9bea7797e2f529f4414eb25a35`. The bridge's independently qualified R1 source pin remains `816efb4643a7c33e2943b1a64f08971638f517e0`; local compatibility tests use that exact checkout. Do not replace the native running checkout to install the bridge.

## Remaining acceptance

Ops/Agents2 administrator admission and host installation/cutover remain deferred. Still required: protected runtime identities and networking, installed artifact provenance, actual Linux UID/cgroup isolation, a real Go/R1 tool turn and continuation/cancellation, native feed/mark reconciliation, real mail delivery, measured off-host restore, canonical-origin authentication and route promotion/rollback. The goal remains incomplete. The current workflow above covers the additive metrics/history/status source; it does not close target-host acceptance.

## Following backend change: local validation

- Full-period native history replaces the latest-1,000-raw-row truncation. Existing weekly/monthly/quarterly/yearly query values select the complete interval; bounded sampling retains original endpoints and decimal values without deleting observations. Dashboard/portfolio metrics remain unchanged. Valuation history, snapshot sync and dashboard selection: **13 tests /138 assertions passed**, exit0. Changed PHP files passed level7 PHPStan (serial `--debug`, after the sandbox denied its parallel loopback listener) and Pint.
- Additive private `usage`, `context` and `r1_reader_usage` events whitelist observed counters and identify unverified runtime capacity. Missing/default usage and cost remain unknown. Native report outcomes preserve original receipt status, independent of systemd exit status. An empty regular enabled marker remains enabled. Root observation requires an explicit validated native user and local user transport; missing admission makes status unknown without querying root's bus.
- Actual pinned OMP SDK plus the exact local R1 source ran **40 selected tests**, exit0 in4.511s, including metrics, streaming, session paths, native adapter and23 portfolio/status tests. Eight changed Python files passed syntax and the500-line source limit; scoped whitespace checks passed. No provider request, native write or host-policy change occurred. CI now selects the fast metrics/status modules as well as the existing SDK checks.
- Receipts: `.orchestration/cache/backend-history-final.log`, `backend-history-phpstan.log`, `backend-bridge-final.log`. This source subsequently passed the current immutable-image workflow recorded above. Claude's concurrent frontend working tree is excluded from these backend changes.

Skipped broad suites/repository-wide lint, local Docker startup, provider calls for the new protocol checks, native repairs and production host/domain changes.

## Local recovery-engine qualification

Operations' exact candidate scope remains `server/production/backup/server2/trading-hub/`, recorded in `docs/deployment/receipts/operations-manifest.json`. Added a nearest-scope integration check using installed Restic0.19.0 and a temporary encrypted local repository, protected synthetic password file and scrubbed environment. It runs the actual capture/publisher/Restic backup/readback/checksum paths, validates a restored synthetic SQLite database captured with an open WAL connection, preserves the source logical rows and rejects corrupted capture input before another Restic operation. Existing snapshots remain unchanged on that failure. No production data, credentials, SSH transport or retention deletion is used.

The test substitutes only the SFTP repository transport with the exact local fixture. Its publisher return value is not independent off-host evidence. **14 nearest-scope tests passed**, exit0 in8.148s, including the real engine test; syntax and scoped whitespace checks passed. Receipts: Operations `.orchestration/cache/trading-hub-{restic-roundtrip,backup-checks}.log`. Temporary repositories/passwords/restored files were removed by the test. The Operations candidate is still uninstalled/uncommitted and is referenced by exact file hashes.

This proves local archive mechanics only. Actual SFTP publication, PostgreSQL/application/key restoration, cross-host consistency, canonical-origin auth and measured one-hour RPO/four-hour RTO remain unverified. Production availability, backup execution and recovery readiness remain separate unknown states.

## Durable pre-admission cancellation follow-up

Reproduced a real Stop race: Laravel commits `dispatching`, but cancellation reaches the bridge before its first PUT. The old404 response leaves `cancel_requested`; dispatch skips admission and reconciliation repeats404 forever. Red receipts are `.orchestration/cache/research-cancellation-red.log` and the PHP lane's focused reproduction. No UI changes are required.

The bridge now records researcher/run-UUID cancellation receipts separately under the same SQLite write lock as admission. A committed pre-admission receipt returns `cancelled`; late PUT returns409 without an executor submission. Foreign identities get404, receipts survive restart, and no session/request/event is fabricated. Already admitted work remains `cancel_requested` until actual confirmation. Laravel's committed dispatch claim is unchanged, including after ambiguous transport failure; no network-spanning database transaction or false404 acknowledgement was introduced. [Protocol contract](../../docs/api/research-cancellation.md).

Root checks: **19 bridge API/SQLite tests passed**, exit0 in0.215s, and **31 selected PHP tests /117 assertions passed**, exit0. PHP tests use HTTP fakes; actual durability/late-PUT rejection are verified independently by the bridge tests. Python/TOML/CI Bash syntax, scoped Pint and whitespace checks passed. Local API tests reused pre-existing FastAPI0.109.2/HTTPX0.26.0, older than the declared runtime floor; the source workflow now installs the actual declared bridge dependencies, pinned SDK and explicit HTTPX0.28.1 test extra before Linux protocol checks. No global dependency changes occurred. Receipts: `backend-cancellation-{php,bridge}-final.log`.

This is a bridge-only runtime correction; the Laravel application runtime/image inputs are unchanged from the accepted image above. Linux source qualification and exact private bridge commit must be recorded separately. No unchanged image rebuild or target installation is claimed.
