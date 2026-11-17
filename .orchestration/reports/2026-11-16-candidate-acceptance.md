# Trading Hub local candidate acceptance

Status: implemented and validated locally; **not deployed or production accepted**. The long-horizon goal remains active. Both `ack execute` and `ack release` are recorded. The owner deferred administrator access and host installation/cutover while prioritizing the exact Capitalio correction. The source below and its checks are the original historical candidate; the Capitalio acceptance receipt and subsequent release preparation supersede its design/publication status.

Application source: `a4ae734ba9615223a647f82c112b4d331a73b5d9` on `codex/trading-hub`, tree `484bac1463f36cf29d0a865e22f91e7d8c6b709d`, based on official Laravel starter `012f4248f70e255e32ad93f540aca5fb7e8c96fe`. `git status --short` was empty after the implementation commit. At that checkpoint nothing was pushed or published. Subsequent private source/image publication is recorded in [the release preparation receipt](2026-11-17-private-image-candidate.md).

Canonical source checkout: `/Users/jonasknppel/Businesses/Kernscale/repositories/products/Kernscale-Trading`. Writable implementation checkout: `/Users/jonasknppel/Businesses/Kernscale/repositories/internal/Kernscale-Trading`. Native TradingAgents, Trading-CLI and Trading-R1 remain independently owned; their paper ledger, risk settings, timers and services were not changed.

## Delivered scope

- One Laravel13/React app with Capitalio-derived dark layout, selected shadcn chat/trace primitives and exact React Bits LatticeLoader. Home, private R1 Research, outputs/review/strategies, community, portfolio and membership/security pages.
- Invitation-only membership; official Laravel passkeys plus mandatory authenticator/recovery; revocation and last-authenticator protections. Owners do not inherit private researcher sessions.
- Private sessions, durable idempotent run intent, polling/cancellation reconciliation, safe evidence/Markdown, versioned thesis/cards and four-column Kanban. Agent workspace changes require explicit human application.
- Scheduled native receipts/artifacts projected separately; shared native paper account plus private watchlists only. Verified future valuation observations accumulate without fabricated history. Read-only native bridge and independent marks capture preserve ledger authority.
- Pinned OMP/R1 bridge, private researcher identities/directories, explicit Go route and app/session headers, SSRF protections, actual tool registry and durable evidence. Linux admission and real provider execution deliberately remain unqualified.
- Local immutable image/runtime validators and separate Hosting/Operations package candidates for scoped deployment and lean encrypted recovery. No backup deletion, installation or host/ingress mutation.

## Final observed checks

All commands below exited0. Logs are private, ignored under `.orchestration/cache/`.

| Check | Observed result | Receipt |
| --- | --- | --- |
| Explicit affected Laravel feature selection below | 98 tests, 376 assertions, passed | `candidate-feature-checks.log` |
| PHPStan configured level7, `vendor/bin/phpstan analyse --debug --no-progress --error-format=json -v` | zero errors | `phpstan-final.log` |
| `npm run types:check` | TypeScript passed | `types-final.log` |
| Changed PHP Pint, frontend lint/format, `git diff --check` | passed | `pint-candidate.log`, `frontend/board-*`, tracked diff |
| Frontend research-intent, portfolio eligibility and Markdown security assertions | unchanged retries retain intent, provenance eligibility and unsafe-link/HTML rejection passed | named frontend assertion scripts |
| Pinned SDK/R1 worker and session-path selection | eight tests passed; lazy first-turn file creation and strict resume protected | `root-worker-final.log` |
| `python3 deploy/tests/test_runtime.py` | three tests passed using actual Compose config resolution with synthetic values | `root-runtime-final.log` |
| Exact OMP18.21.0 CLI on checksum-verified project-local Bun1.4.4 | ready/get_state/new_session/open_session metadata, exit0 and clean teardown | `omp-cli-qualification/actual-cli-receipt.json` |

The final Laravel command selected these paths explicitly, rather than invoking the repository-wide suite:

```sh
php artisan test --compact \
  tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/RegistrationTest.php \
  tests/Feature/Auth/InvitationTest.php tests/Feature/Auth/MembershipTest.php \
  tests/Feature/Auth/OperationalSecurityTest.php tests/Feature/Auth/TwoFactorChallengeTest.php \
  tests/Feature/Settings/ProfileUpdateTest.php tests/Feature/Settings/SecurityTest.php \
  tests/Feature/DashboardTest.php tests/Feature/ResearchLifecycleTest.php \
  tests/Feature/ResearchDispatchStateTest.php tests/Feature/ResearchSessionManagementTest.php \
  tests/Feature/WorkspaceProposalsTest.php tests/Feature/PublicationTest.php \
  tests/Feature/PortfolioToolsTest.php tests/Feature/ValuationHistoryTest.php \
  tests/Feature/SnapshotSyncTest.php tests/Feature/LegacyTradingImportTest.php \
  tests/Feature/ReadinessTest.php tests/Feature/ProxyBoundaryTest.php
```

Earlier scoped bridge API/network/portfolio/marks, real fixture HTTP, Hosting and Operations checks are recorded in their lane reports; the final delta did not change those interfaces. An external autoreview helper was rejected by automatic approval review because it could export private candidate source to another model service. It was not retried and no such source export occurred. Root manual review and local validation were used; independent external review is not claimed.

Companion candidates remain uncommitted in their owning repositories, with unrelated work preserved. Their exact source files were rechecked against versioned SHA256 manifests:

| Owner and base | Candidate | Exact check and observed result |
| --- | --- | --- |
| Hosting `b1e335aceb5f0f5ea96f761bee7bcb7e7281af28` | `server/production/deployment/kernscale-trading-hub/`; `docs/deployment/receipts/hosting-manifest.json` | `python3 -B server/production/deployment/kernscale-trading-hub/tests/test_contract.py`: exit0, three tests passed |
| Operations `f354cb81da8f93cd8367fd0ca232b9b8211d4eae` | `server/production/backup/server2/trading-hub/`; `docs/deployment/receipts/operations-manifest.json` | `python3 -B -m unittest discover -s server/production/backup/server2/trading-hub/tests -p 'test_*.py'`: exit0, thirteen tests passed |

## Authenticated local browser acceptance

An isolated synthetic owner account exercised actual HTTP/UI behavior at `http://localhost:8007`:

- Passkey registration, password+TOTP login, full passkey+TOTP login and passkey security reconfirmation succeeded. Recovery codes were absent initially, eight appeared only after View and were removed from the DOM after Hide; code values were not included in accepted receipts.
- Created, renamed and pinned a private session; saved a thesis; created/moved/edited a real board card. A forced stale version returned an inline error and preserved the draft, while non-Inertia callers retain409 semantics.
- Submitted synthetic research for owner review and published it; saved a private watchlist symbol independently of native source availability; posted a community discussion and reply; rendered owner administration.
- Desktop1280x720 composer bottom670 remained visible. Mobile390x844 draft retention, navigation focus trapping/Escape/focus return and no document overflow passed. Final fresh-mobile board rectangle was exactly `[0,390]` by `[0,844]`; header/editor stayed within390 pixels. Final checked research/board navigation emitted no new console errors after sibling-key correction.
- Safe Markdown rendered semantic tables and left an executable-link fixture inert.

Sanitized receipts and screenshots remain under `.orchestration/cache/acceptance/`. The synthetic membership was revoked, its password randomized without retaining the replacement, MFA/recovery and virtual passkeys removed, and all its DB sessions invalidated. Credential-bearing browser helpers/logs and trace cache were deleted. No real member credentials or production state were involved.

## Production gates

1. Release authorization is recorded. Keep host installation/cutover deferred until the owner resumes authenticated administrator admission; do not request the same release approval again.
2. An authenticated OpsVM100 administrator or admitted app-scoped interface must inspect/admit Docker, ingress, exact allowlists, volumes and immutable image identity. Existing normal Ops login cannot access Docker or noninteractive sudo; the VM101 wrapper and PVE backup privilege are not deployment substitutes.
3. Build and inspect the production image/assets; validate actual schema promotion/rollback and singleton worker fencing. Qualify Agents2 UID/cgroup confinement, installed artifact provenance, real Go/R1 tool turns and continuation/cancellation, and private Ops-to-Agents2 transport.
4. Configure protected mail and canonical-origin passkeys/MFA, reconcile live native ledger/output projections and any explicitly reviewed legacy imports, and perform measured encrypted off-host restore. No one-hour RPO/four-hour RTO acceptance is claimed.
5. Verify authenticated desktop/tablet/mobile behavior on the canonical domain, then promote with rollback and retire superseded frontends only after replacement acceptance. Native agent processes and paper schedules continue.

Not run: full repository suites/lint, production asset/image builds, physical/canonical authenticator checks, real provider prompts, Linux isolation acceptance, live legacy import, off-host restore or cutover. Process health alone is never acceptance.
