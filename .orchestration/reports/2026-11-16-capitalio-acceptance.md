# Complete Capitalio correction — local acceptance

Checked implementation: `27859f6c4ca5bfb3231820410cf83e65a2296c4b`, branch `codex/trading-hub`, tree `132659cb43948bb3cf0aa8a9dc71c7d3230b7780`. The worktree was clean after that commit. At this browser acceptance checkpoint no source/image push, host installation or domain cutover occurred.

Subsequent release preparation: the corrected frontend production build passed (`npm run build`, exit0, 3.39s; `.orchestration/cache/capitalio-production-build.log`). Source through `f67598679ae02121e322d5f6261e1a5f86d81df0` was published to the private owning repository; push validation passed. Host installation/cutover remains deferred. The original browser and source checks below remain scoped to their recorded revision.

The [17 November private image receipt](2026-11-17-private-image-candidate.md) records successful publication and actual immutable image-config verification through `86cd966c678d5aaf74b752beb7731691bf2bac0b`. Its application frontend is unchanged from the accepted Capitalio correction.

The owner rejected the earlier visual interpretation and required the complete selected registry design. The new implementation preserves all twelve original source files byte-for-byte in `resources/js/components/watermelon/capitalio-dashboard/`, guarded by `docs/design-references/capitalio-source-manifest.json` and `tests/Design/test_capitalio_reference.py`. Registry SHA256: `8ca7f46ec8b0dedd5708b36391aae9aa0f131ddd2eddc644bc76e31c0d5d97c5`.

## Actual source and application use

- `/design/capitalio` renders the complete original Dashboard and Analytics pages, all original sample datasets, sidebar, topbar, icons and CSS. A persistent **Sample design data · Local owner preview** marker identifies it. The route requires enrolled approved ownership and returns404 outside the local environment. Original blank demo destinations keep the complete source dashboard; they do not become new software capabilities.
- Native `/dashboard`, `/portfolio` and `/portfolio?view=analytics` retain all eight dashboard and six analytics blocks using the source frame, cards, spacing, metallic icons, active inset, bright palette, dot grid, chart/legend/tooltip structures and footer treatments. Real routes, Kernscale branding and truthful membership replace sample identity/navigation. Other app/account/auth pages use the same visual system; R1 keeps its separately selected chat/thesis/board layout.
- All four period controls filter actual available native observations; the displayed date range uses that same window. Live adapters cannot import sample datasets. Missing return/risk/benchmark/income/classification data remains a compact unavailable state. No native account values, risk formulas, return targets or historical series were fabricated. Actual source/time labels and partial history remain explicit.
- Home research counts, scheduled outcomes, decisions and outputs remain after the financial blocks. Private watchlists and the R1 session/thesis/Kanban capabilities remain intact.
- Inter400/500/600/700 uses the existing bundled-font pipeline. Actual FontFaceSet entries were loaded; all delivered WOFF2 variants returned200 with correct signatures. Official host CSS declares Inter Variable; no separate `font-section` mapping was found or invented. Original source shadows, gradient/mask, conic badges, dot grid and progress animation were verified against the authoritative CSS.
- A six-line compatibility fix in the existing sidebar primitive restores mobile trigger focus after Escape. Existing primitive formatting was preserved. All original Capitalio source files remain unchanged. Missing allocation retains a circular224x224 ring; unknown metric values use em dashes, with concise user-facing copy.

## Observed browser checks

Authenticated localhost browser, synthetic owner and synthetic local documents only. No researcher request, account operation, real mail or ledger change was made by these checks.

| Check | Observed result |
| --- | --- |
| Reference Dashboard at1497x823 | Eight block headings, six painted SVG charts and seven metallic icon badges; complete performance/allocation/holdings/insights sections present |
| Reference tooltip | Visible actual source tooltip with sample Week3 portfolio and benchmark values |
| Four period controls | Weekly/Quarterly/Yearly/Monthly each selected and changed the original dataset |
| Reference Export | Downloaded valid JSON marked `sample_design_data:true`, with summary/detail/bottom and all analytics datasets |
| Full Analysis | Opened original Analytics with all six blocks and six SVG charts, including lower returns and income |
| Responsive reference |390px phone and1024px tablet document widths matched their viewports; closed mobile links absent; focus trapped; active white/inactive gray; Escape focus returned after the fix |
| Native Dashboard | All eight financial block headings plus existing research/schedule/output blocks; no sample financial/user values leaked; Export disabled until a verified snapshot exists |
| Native Analytics/navigation | All six frames retained; exactly Analytics highlighted; no duplicate active Portfolio item |
| Private watchlist | Existing synthetic owner's saved symbol remained visible independently of native source availability |
| R1 at1280x720 | Composer rectangle `x504,y502,width460,height183`, bottom685 within720; contextual header corrected |
| R1 phone/board | Draft survived Context/Conversation changes; card editor opened; dialog exactly390x844 at0,0; document width390 |
| Laravel login theme | Passkey login control present and no Google control; same Inter/blue/Kernscale theme |
| Runtime errors | No page JavaScript errors in the complete reference/native flows |

Safe screenshots and sanitized receipts: `.orchestration/cache/acceptance/capitalio-*` and `kernscale-capitalio-*`. The synthetic owner was signed out/revoked, its password randomized without keeping the replacement, authenticators cleared and DB sessions invalidated after acceptance. Credential-bearing preview helpers/logs and browser trace state were removed; no real member credentials were involved.

## Targeted command receipts

- `python3 -B tests/Design/test_capitalio_reference.py`: exit0, all twelve files pass the exact SHA256 guard.
- `php artisan test --compact tests/Feature/CapitalioPreviewTest.php tests/Feature/DashboardTest.php`: exit0, **five tests / sixteen assertions**. Preview-only selection: three tests / twelve assertions.
- `vendor/bin/phpstan analyse app/Http/Controllers/HubController.php --debug --no-progress --error-format=json`: exit0, zero errors at configured level7.
- `npm run types:check`: exit0 after the final source/empty-state changes.
- Changed frontend lint and formatting, targeted sidebar/Vite lint, changed PHP Pint and `git diff --check`: exit0.
- `node .orchestration/cache/frontend/capitalio-native-data.test.mjs`: exit0, native-source gating, all four actual windows, foreign financial suppression and no sample imports in live adapters.
- Existing research-intent and strict portfolio-data assertions passed after integration. No new runtime dependency or package-lock change remains; the CLI's unused dependencies and extra lockfile were removed.

Detailed lane receipts are in `.orchestration/frontend-capitalio-report.md`. External autoreview was not retried after its earlier automatic approval rejection; root manual review and actual local behavior checks were used.

Skipped: full suites/repository-wide lint, production build of this new revision, physical-device/canonical-origin acceptance, installed Linux OMP/R1 qualification, image publication, recovery drill and deployment. The earlier production asset build belongs to the pre-correction candidate and is not evidence for this revision. Release approval remains recorded; the owner deferred administrator access and deployment while prioritizing this design correction.
