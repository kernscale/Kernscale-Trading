# Read-only native portfolio and scheduled reports

`portfolio.py` reads the authoritative Trading-CLI SQLite ledger directly using `mode=ro`, `query_only=ON` and one read transaction. It never imports `papertrade.db()` (which initializes/migrates), calls quote providers, trades, changes risk or schedules, or contacts the old web UI.

Operator configuration, never request input:

| Variable | Purpose |
| --- | --- |
| `TRADINGCLI_DATABASE_PATH` | Existing native ledger file; `PAPERTRADE_DB` is a compatible fallback. |
| `TRADING_ACCOUNT_ID` | Shared account; defaults to `global-paper`. Other accounts are excluded. |
| `TRADING_REPORTS_ROOT` | Native scheduled research receipt/artifact root; `TRADINGAGENTS_PAPER_REPORTS` is a compatible fallback. |
| `TRADING_SCHEDULE_PATH` | Optional actual systemd research timer unit; displays its `OnCalendar` without querying/changing service state. |
| `TRADING_MARKS_RECEIPT_PATH` | Optional saved native `import.receipt.json` produced by the existing Ghostfolio adapter. |

The API coordinator owns authentication and wraps these functions:

- `portfolio_snapshot(config=None)` → ledger accounts/holdings/orders/cashflows/activities/corporate actions/watchlists, risk settings, metrics, freshness and capability inventory.
- `saved_reports(config=None)` → account-scoped receipt summaries with receipt/report checksums.
- `report_artifact(uuid, config=None)` → saved Markdown or HTML content plus SHA-256 and native receipt provenance.
- `service_status(config=None)` → receipt availability/outcomes, the native enabled marker and timer-file schedule. Worker health and next actual firing remain unverified.

`PortfolioUnavailable` maps to 503; `ReportNotFound` maps to 404. The coordinator wraps the report list as `{reports: [...]}`. Report HTML remains untrusted: render it in a sandbox or as text. Never inject it into the application document.

## Financial truth and freshness

The ledger has no persisted quote table or observed equity series. Missing marks, market values, day performance, returns, Sharpe and drawdown remain null; history remains empty. A cash-only ledger has known cash equity. Cost basis and retrospective daily-close replay never stand in for current marks or actual history.

Optional native saved marks must reconcile cash, funding, realized P/L, the exact number of positions, account-derived UUID identities, quantities, prices, currency and timestamp order. The existing producer supports only long spot positions with multiplier 1; other books retain null valuation. Missing, mismatched, malformed or future-dated receipts supply no marks. Reconciled prices retain their original source, USD currency and observation/receipt timestamps, are always labelled saved/stale, and may produce a clearly qualified equity valuation. They are never represented as live prices.

**Writer boundary:** the existing Ghostfolio mirror produces this receipt while exporting native marks. Retiring that publisher requires replacing its mark-capture writer or accepting unavailable/stale marks. This read-only bridge neither keeps that publisher alive nor creates a replacement market-data scheduler. Do not stop it on the assumption that this adapter captures new marks.

Reports admit only native canonical `YYYY-MM-DD-UUID.json` receipts with the configured account and matching started-date identity. Artifacts are read only from `UUID/reports/complete_report.md` or `.html`. All path components are opened relative to the configured root using `O_NOFOLLOW`; symlink files/directories, traversal IDs, nonregular files and oversized artifacts are rejected. Receipt and artifact checksums preserve source provenance across repeated imports. No receipt contents can select a filesystem path.

## Capability inventory and gaps

| Capability | Current adapter |
| --- | --- |
| Paper account cash/funding/realized P/L and signed holdings | Available directly from native SQLite. |
| Orders, cashflows, corporate actions, watchlists, native risk settings | Read-only, account scoped. |
| Saved market valuation with source/currency/freshness | Conditional on a reconciled native saved mark receipt. |
| Scheduled research, saved reports, outcome/status and schedule definition | Available from native receipt files and optional timer file; process liveness is unverified. |
| Actual equity history, daily/risk-adjusted returns, benchmark and allocation analytics | Not established; no invented series or backfilled market history. |
| Manual activity import/export and edits | Native CLI supports import/export and account operations; no write bridge exposed here. |
| Manual orders, paper controls and risk/schedule changes | Remain native operator-owned actions; no mutation endpoints. |
| Ghostfolio-specific analytics, asset/market-data editing and full administration parity | Not established by this adapter. Keep legacy functionality until separately accepted replacements exist. |

Source inspection: Trading-CLI `papertrade.py` schema and functions `db`, `equity_curve`, `account_performance`, `account_activities`; TradingAgents `tradingagents/integrations/tradingcli.py` and `tradingcli_outcomes.py` for canonical receipts; `tradingagents/integrations/ghostfolio.py` for actual-fill export and saved mark receipts; `deploy/tradingcli/tradingagents-paper-research.timer` for schedule syntax. These are source findings, not live acceptance of the Agents2 files or processes.

Offline receipt: `python3 -m unittest bridge.tests.test_portfolio -v` passes 9 tests, including unchanged synthetic database hash, scoped ledger data, absent-database noncreation, null unknown history, reconciled/mismatched saved marks, repeated checksum-stable reports, account isolation and traversal/symlink denial. No provider, real paper account, production process, browser or network was used.
