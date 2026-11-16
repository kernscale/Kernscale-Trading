# Trading Hub implementation ledger

Goal: complete the approved unified Laravel Trading Hub. User authorized local auth/isolation with `ack execute`; production cutover awaits `ack release`.

Baseline: official Laravel React starter kit commit `012f4248f70e255e32ad93f540aca5fb7e8c96fe` (no production state copied). New source repository is `products/Kernscale-Trading`; writable linked implementation checkout is `internal/Kernscale-Trading`, branch `codex/trading-hub`.

## Boundaries and acceptance

- Root: domain models/API, durable research lifecycle, source integration and final targeted acceptance.
- Authentication lane: Fortify, invitations, enrollment, membership, role authorization and auth tests.
- Frontend lane: Capitalio application shell and pages, shadcn agent presentation, responsive interactions, frontend checks.
- Research bridge lane: isolated OMP/R1 worker protocol, real finance tools and durable event bridge; offline integration checks.
- Deployment lane: new Hosting/Operations app-scoped release/recovery files and offline checks. No host mutation.

Acceptance requires real data provenance, cross-user denial, idempotent research dispatch, native ledger preservation, usable responsive pages, and exact check receipts. A running process alone is not acceptance. Real canonical-origin passkeys, provider run, off-host restore and production cutover remain release gates.

Ruling: use a linked writable checkout because the native worktree helper did not return. The canonical source checkout is preserved.

## Progress

- Repository scaffold created. No engine, services, credentials or traffic changed.

- Local dependencies installed: Laravel13.42, Fortify1.x/passkeys0.2.1, Spatie8.5, Horizon5.51; npm locked. No production build or image created.
- Auth lane: 55 targeted tests / 200 assertions passed. Invitation-only membership, required passkey+TOTP, TOTP assurance after passkey/remembered login, owner controls and last-authenticator retention.
- Domain lane: 11 targeted tests /39 assertions passed for private sessions, immutable/idempotent dispatch, report publication/revisions, stale snapshots, private watchlists/export.
- Frontend lane: six responsive pages, selected Capitalio/shadcn/React Bits sources, safe Markdown; TypeScript and focused lint/format plus replay/source/XSS checks passed. Browser acceptance in progress.
- Bridge lane: pinned OMP SDK/R1 offline tool/evidence and real HTTP fixture checks pass; actual CLI/Linux isolation/provider qualification remains required and production dispatch stays disabled.
- Legacy lane: eight tests/32 assertions passed for explicit immutable normalized imports and denial of private content/privilege mapping. Real export conversion/reconciliation pending.
- Hosting/Operations: scoped candidate packaging and lean native capture/off-host verification helpers pass offline tests. Live read-only Ops inventory found Docker denied and sudo requires authentication; ingress/admission not verified.
- Ruling: native scheduled run receipts are displayed separately from immutable research artifacts, allowing pending receipts to acquire their real report later.
- Pending user scope decision: separate private tracked portfolios versus shared paper portfolio plus private watchlists. Preserve shared native paper authority either way.

- User confirmed shared paper portfolio and private watchlists only; no private financial ledger/account module.
- Independent review helper was rejected by automatic approval review because it could transmit private candidate source to another model service. No helper retry or source export occurred; root continues manual review and local receipts.
- Manual review found Horizon's vendor queue list omitted `research`; explicit app queue configuration added. Readiness now checks actual schema/migration and immutable production release identity rather than only DB connectivity.
- Browser verified synthetic TOTP enrollment and passkey registration on localhost. Post-enrollment/login session behavior remains under diagnosis; do not claim browser auth acceptance yet.

- Actual read-only Agents2 metadata: native 11MB paper ledger and research directories exist; research/tick timers and existing UI service are active. Service state alone is not proof of successful research.
- No admitted Ops admin/deploy interface is currently evidenced: Docker denied, noninteractive sudo requires authentication; VM101 release wrapper must not be reused for OpsVM100.
- Workspace proposals backend: 11 focused tests/47 assertions. Root integration with research lifecycle: 15 tests/49 assertions passed before final proposal route additions. Agent changes remain pending until explicit human apply; user/context ownership and optimistic versions are checked.
- Bridge review fixes include bounded capacity, 500-event pagination with complete draining, real native session-file continuation, and typed workspace tools. Readiness now also refuses missing schema/release identity; actual Horizon `research` queue subscribed.

- Runtime browser proofs: actual synthetic passkey+TOTP enrollment; password+TOTP reaches protected Research; session rename/pin, thesis save and board creation work. Menu keyboard trap/Escape/focus restore passed at390x844; closed mobile navigation absent. Desktop1280x720 composer bottom670<=720. Empty-state computed foreground238/238/239 after contrast correction. Cross-panel draft preservation and no horizontal overflow passed.
- Browser harness correction: Playwright CLI `open` creates a new browser context; the apparent session loss was test misuse, not app behavior. Subsequent navigation uses the same page/context.
- Native fresh marks13 focusedtests passed; thin actual-capture history5tests/21assertions plus app integration10tests/37assertions passed. No historic curve reconstructed or native ledger changed.
- Proxy HTTPS-origin boundary is wired using the existing Kernscale Laravel pattern. Trusted loopback succeeds; untrusted X-Forwarded-Proto is ignored. Production readiness rejects HTTP origins/insecure cookies. Schema/secure-origin/home checks5tests/9assertions passed.
- Public reference assets are now retained in docs/design-references. Third-party source licenses/attribution remain alongside components. No source was sent to the rejected external review helper.

- Final bounded backend feature checks: 104 tests / 387 assertions passed (explicit affected feature paths, not the broad composer/full-suite scripts). Latest PHPStan level7 passes with zero errors; final rerun follows source integration.
- Browser confirmed private session creation, thesis save/version2, board creation, session rename/pin and real draft creation. Responsive acceptance: closed mobile nav count0, focus trap true, Escape focus restoration true; composer bottom670 at1280x720; no horizontal overflow and drafts preserved across panels. Text contrast now rgb(238,238,239).
- Thin verified valuation history integrated with snapshot acceptance; stale/invalid observations do not become chart points. 5-minute buckets preserve actual capture time and all older DB rows; chart view fetches latest1000 points. No synthetic history or benchmark is generated.
- Trusted proxy configuration uses the existing Kernscale Laravel pattern and an admitted address/CIDR list; outside callers cannot supply HTTPS origin. Production readiness requires HTTPS and secure cookies. Home redirect regression updated for closed member workspace.
- Current candidate remains local. No releases, public writes, provider calls or native ledger/schedule changes. Ops admin access and `ack release` are still required before production deployment.
