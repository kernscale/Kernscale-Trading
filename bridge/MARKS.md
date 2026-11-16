# Independent native mark capture

`python -m bridge.marks` is an operator-only collector, independent of the Ghostfolio publisher. It writes the saved receipt consumed through `TRADING_MARKS_RECEIPT_PATH`. It does not expose an HTTP endpoint, publish to Ghostfolio, place orders, migrate/initialize a database, update native risk or change research scheduling. No real provider request or production collector run occurred during this implementation.

## Trusted configuration

Create a private state directory beforehand and supply operator-owned configuration:

```sh
python -m bridge.marks \
  --database /trusted/native/paper.sqlite \
  --account global-paper \
  --output /private/mark-state/latest.receipt.json \
  --native-checkout /trusted/pinned/Trading-CLI
```

Equivalent defaults: `TRADINGCLI_DATABASE_PATH` (or `PAPERTRADE_DB`), `TRADING_ACCOUNT_ID`, `TRADING_MARKS_RECEIPT_PATH`, and `TRADINGCLI_CHECKOUT`. These arguments/environment variables must come from the service operator, never an HTTP caller or model-produced tool argument. Preserve the existing native market-data/admission environment, including `PAPERTRADE_FUND_MODE` and any approved provider settings. This command does not create or print provider credentials. The checkout and its Python dependencies must be pinned and trusted; importing arbitrary Python directories would execute their code.

## Native read and write boundary

The collector reads accounts/positions and admission/split-block configuration through SQLite `mode=ro`, `query_only=ON`, with a read transaction. It creates a consistent private online SQLite backup for quote helpers using SQLite's backup API. The temporary directory is mode 0700 and its database is mode 0600. Helpers run in this dedicated short-lived CLI process with `PAPERTRADE_DB` pointing at that copy; the environment is restored and the copy removed in `finally` cleanup.

This separation is necessary: inspected `market_quotes._split_block` opens `mode=rw` and records split-detection diagnostics. Running it directly against the authoritative database would violate the read-only collector boundary. The native helper is unchanged and may write such diagnostics only to its disposable private copy. A detected split still fails the observation and requires the native operator to reconcile the authoritative account; this collector never clears or copies diagnostic state back.

The actual provider remains native `market_risk.mark`, with `market_assets` admission, `market_quotes.fetch_quote`, currency conversion and completed-session validation. `papertrade.db`, `market_risk.portfolio_snapshot` and Ghostfolio export/publication are never invoked. Native module origins must match the configured checkout. The original book/admission state must match the private snapshot before each quote, and the actual book/admissions must still match after capture before a receipt is published.

Do not import/call `native_quote_provider` from the shared coordinator or an HTTP worker: its temporary environment/module-path state belongs exclusively to the dedicated operator process. Keep the authoritative SQLite directory and native source checkout read-only to that service at the OS level as defense in depth. The state directory alone needs writes. Preserve access to the ledger's existing WAL/SHM as needed for read-only online SQLite; do not use `immutable=1` against a live WAL database or ignore committed WAL data.

## Receipt contract and failure behavior

Receipts contain `captured_at`, `cash`, `net_funding`, `realized_pnl`, `position_count`, account-derived position UUIDs/quantities/prices, and observations preserving original source, currency, observed/received timestamps and native FX/completed-session metadata. The source is `native-independent-mark-capture`. Existing portfolio reconciliation applies unchanged.

Only positive-quantity spot holdings with multiplier 1 are qualified, matching the existing native saved-mark contract. Shorts, futures, options, unknown asset classes, nonfinite numbers, unknown currency/source, future/inverted timestamps and stale quotes without a native validated completed-session valuation are rejected. There is no cost-basis valuation fallback, synthetic quote or missing-history backfill. Cash-only accounts can produce an empty observation receipt.

All positions must succeed. A quote/validation/book-change failure leaves the prior receipt untouched. The completed JSON is encoded with nonfinite values forbidden, written to a mode-0600 temporary file in the destination directory, flushed/fsynced, atomically replaced, and the directory fsynced. A failure before replacement retains the prior file and removes the temporary file. A durability error after successful atomic replacement can leave the newly validated receipt in place; operators must inspect the private file after such a failure. The output cannot be a symlink or the native DB/WAL/SHM path.

## Retirement and schedule acceptance

No service or timer was installed/enabled. Before stopping the old Ghostfolio publisher:

1. Qualify this collector on Agents2 against the actual pinned native checkout and approved provider environment. Reconcile cash, quantities and mark provenance against the native account without changing its ledger/risk settings.
2. Admit a separate short-lived systemd service with one collector owner, private state and environment, read-only native DB/source paths, no unnecessary privileges, and network access limited to the existing approved quote providers. Set an execution timeout and service-level single-instance admission; do not add a second research/tick scheduler.
3. Select and verify the mark-capture timer cadence and monitoring independently of the existing research schedule. No new cadence is silently assumed by the implementation. Confirm completed-session and closed-market behavior, failed-capture retention, successful fresh receipt consumption, staleness alerts and observed valuation-history persistence.
4. Point the bridge at the independent receipt, verify its deployed identity and actual observations, then explicitly retire the Ghostfolio publisher through the separately authorized release. Keep native paper execution/research timers and ledger ownership intact.

The collector replaces only saved mark capture. It does not establish complete Ghostfolio feature parity, historical performance availability or cutover acceptance.

## Offline checks

`python3 -m unittest bridge.tests.test_marks -v` passes 6 tests using synthetic ledger data. Checks cover immutable authoritative DB checksum, receipt/portfolio reconciliation, atomic private writes, quote failure and replacement-failure retention, changed-book refusal, invalid/stale/unsupported observations and cleanup. One source-path qualification imports the existing Trading-CLI helpers, replaces only their external HTTP fetch in the test with a synthetic Kraken response, exercises the real native quote parser, and proves split-diagnostic writes affect only the private backup. No external provider call is made.

Native source inspected/qualified locally at Trading-CLI HEAD `ef5d9ba5a692acdee53cc3348926a97f9f0339cc` (working files, not a clean-revision assertion):

| File | Observed SHA-256 |
| --- | --- |
| `market_risk.py` | `b7104c7144f0468a7fc75309c08610b0ce00dc9ad448853c5a720ea1f53fa813` |
| `market_quotes.py` | `e6f691e5f4f9a5c34b9deb912e5027f882cb9750a49046d60ebb2d55c5275872` |
| `market_assets.py` | `099f307bdde70b416ea61fe930299822fb89879fe7b601cc50669cb414e8a947` |
| `market_world.py` | `7c76357e35e9e793a45ec70ed0d2e431d9714429dc35e7f4dd24b08db60c0a58` |
