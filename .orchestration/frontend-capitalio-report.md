# Exact Capitalio source implementation — 16 November 2026

This supersedes the earlier custom visual interpretation. The user explicitly required the complete Capitalio registry design, rather than visual inspiration. Local implementation only; no commit, production build, deployment or live finance acceptance is claimed.

## Source and reproduction

The exact requested command completed:

`npx shadcn@latest add https://registry.watermelon.sh/r/capitalio-dashboard.json --yes`

All existing authentication/UI primitives were preserved at overwrite prompts. CLI-generated duplicate output directories, duplicate mobile hook, unused `cn`/`radix-ui` dependencies and `pnpm-lock.yaml` were removed. `package.json` and the canonical `package-lock.json` have no final diff; no new runtime dependency was introduced.

All **12 original files** are retained byte-for-byte under `resources/js/components/watermelon/capitalio-dashboard/`. The original long dashboard/icons/data remain unchanged upstream source. `python3 -B tests/Design/test_capitalio_reference.py` passes their hash guard. The full twelve-file reference-to-implementation map is in `resources/js/components/capitalio/SOURCES.md`, with root acceptance mapping in `docs/design-references/capitalio-parity.md`.

The enrolled local-owner preview at `/design/capitalio` renders original complete Dashboard and Analytics components, their original data, original layout/sidebar/CSS/icons and all four period choices. Its persistent **Sample design data · Local owner preview** marker distinguishes demonstration values. Full Analysis opens original Analytics; Export downloads marked sample JSON. Original blank demo destinations retain the complete source dashboard rather than blank content. Root owns route authorization and its three passing boundary tests.

## Native implementation

- Actual app frame copies the source SidebarProvider width, outer inset, main scrolling, topbar spacing and shadow. Sidebar preserves exact source active inset, SVG style, blue brand badge and collapsible groups, with Kernscale identity and actual authorized routes/user/role/sign-out. The original gold badge displays truthful Owner/Researcher/Member labels rather than a paid-plan claim.
- Source `dashboard.css` is imported directly and remains unchanged. Compatibility CSS handles the installed sidebar's `data-active="false"`, including portaled mobile navigation; inactive labels remain gray, active white. Straight action arrows preserve the user's preference. Original chart-direction arrows remain unchanged. Reduced motion disables decorative transitions; native charts have animation disabled.
- Native shared cards, headers, tags, tooltip and markers use the source visual code. There is no remaining old mint custom frame. Authentication receives the same blue primary/Kernscale identity; account and all nonfinancial pages use the source frame/card primitives.
- **All eight Dashboard blocks remain:** four summaries (net worth/sparkline, return/target/progress/bars, asset-class strip, risk area), performance with dotted grid and benchmark legend/class-metric slots, segmented allocation donut, full responsive holdings table and all three Smart Insights rows with metallic icon badges.
- **All six Analytics blocks remain:** portfolio/benchmark/60:40 performance frame, three metric/mini-chart frames, grouped period returns and stacked income frames. Missing native series retain compact honest unavailable states rather than disappearing or importing sample values.
- Validated native equity/history and complete USD holding valuations feed the eligible charts. Source `native-paper-ledger` gates **all** native financial values, including metrics, history, raw portfolio detail and currency labels. Foreign snapshots cannot be shown as native/USD. Holdings remain allocation by symbol; native `spot` is not transformed into unsupported asset classes. Limits are not interpreted as a risk score, and observed equity is not investment return. No benchmark/return/target/class/tax/DRIP/income values are invented.
- All four real period controls filter the supplied native observation history; header dates use that same filtered range. Partial coverage/capture timestamps remain explicit. Export is a disabled source-styled control with an explanation until a verified native snapshot exists; actual portfolio tools apply the same guard.
- Prior Home counts, scheduler/latest outcome, decision receipt and recent outputs remain as additional source-styled blocks after the full financial baseline.
- Existing R1 private session/chat/context/thesis/Kanban behavior remains. Its source-styled frame now uses remaining-height flex containment rather than a viewport guess, preserving a visible composer across the new header dimensions. No research intent, authorization or passkey backend behavior was changed.
- Removed obsolete `hub/portfolio-charts.tsx`; retained `portfolio-data.ts`, now reused by native adapters.

## Validation receipts

All passed against the final local working tree:

- `npm run types:check` — exit 0, `capitalio-types.log`.
- Changed frontend `vp lint` — exit 0, zero warnings, `capitalio-lint.log`.
- Changed authored `vp fmt --check` — exit 0, `capitalio-format-check.log`; raw vendor files deliberately excluded.
- `python3 -B tests/Design/test_capitalio_reference.py` — exit 0, all twelve sources unchanged, `capitalio-source-check.log`.
- `node .orchestration/cache/frontend/capitalio-native-data.test.mjs` — exit 0: source gating, four real observation windows, foreign-value suppression, and a boundary preventing sample dataset imports into live adapters.
- Existing research-intent and strict portfolio eligibility assertions passed after the integration.
- `git diff --check` for tracked owned frontend files — exit 0.
- All authored source files remain below 500 lines (largest changed page: Research, 442 lines). Unmodified upstream files are preserved under the documented reference exemption.

Logs are under `.orchestration/cache/frontend/`. Source, route, deployment and other lanes' changes were preserved. Root's early settled preview inspection reported all eight dashboard headings, six painted SVG charts and seven metallic badges, close reference geometry, and no browser page errors. Final reference/mobile/native/Research/auth browser checks remain in the root-owned authenticated browser; this lane did not operate that browser. Root was notified after HMR stabilized for that final pass.

## Final runtime findings: focus restoration and host font

Root's authenticated browser verified all eight Dashboard headings, six painted SVG charts, seven metallic badges, all four working period values, tooltip, sample JSON export, Full Analysis/all six Analytics blocks, 390/1024 document bounds, focus trapping and no JavaScript errors. It found two concrete finish items.

1. Mobile Escape left focus on BODY because the existing controlled Sidebar Sheet has no SheetTrigger reference. Added only a six-line `onCloseAutoFocus` handler in the existing sidebar primitive: prevent the missing default restoration and focus `[data-sidebar='trigger']`. The pre-existing >500-line primitive was not split or broadly reformatted; root explicitly authorized this focused compatibility/accessibility exception.
2. Authoritative host theme evidence in `capitalio-global-tokens/receipt.json` names Inter Variable as the global sans family. Switched the existing Bunny pipeline to Inter 400/500/600/700 and global `--font-sans` to Inter. No speculative `font-section` mapping, font package or other dependency was introduced. The normal Vite configuration reload completed.

Targeted Vite lint, direct primitive `oxlint --no-ignore --type-aware --deny-warnings`, TypeScript, CSS/Vite formatting and tracked whitespace checks passed. The generated dev manifest declares all four Inter weights. Each local WOFF2 URL returned HTTP 200 with `font/woff2`, a valid `wOF2` file signature and permissive font CORS; byte/hash receipts are in `inter-font-delivery.json`. Package/lock files remain unchanged. Root was notified after HMR stabilized to verify actual FontFaceSet loading and post-transition focus restoration; this lane did not operate that browser or repeat unrelated backend/full-screen checks.
