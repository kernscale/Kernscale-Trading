# Native portfolio mark publisher

These system units belong to the new application. They run `bridge.marks` as
`jonas`, without starting the retired native UI or Ghostfolio mirror and without
altering native research/tick timers. The existing mark implementation opens the
authoritative SQLite ledger read-only, makes an online backup for native quote
diagnostics, compares the authoritative account/book/admissions before and after
capture, and atomically replaces a mode0600 receipt only after all observations
pass. Quote/provider failure retains the previous receipt; consumers must retain
its actual timestamps rather than claim freshness from timer success.

## Interpreter evidence and admission

Read-only Agents2 inspection on2026-11-18 confirmed that
`/home/jonas/repositories/products/TradingAgents/.venv-paper-ui/bin/python`
resolves to
`/home/jonas/.local/share/uv/python/cpython-3.13.13-linux-x86_64-gnu/bin/python3.13`.
Package metadata in that interpreter reported `pandas=3.1.1` and
`exchange-calendars=4.13.2`; no native quote module was imported and no provider
was contacted for that inspection. Those are the native completed-session
valuation dependencies in `market_risk.py`/`market_world.py`. The native installer
already uses this venv and pins these versions in
`TradingAgents/deploy/tradingcli/ui-requirements.txt`. Its directory name does not
require its UI service to remain active. Retain this interpreter and its resolved
runtime when retiring legacy services. There is no Trading-CLI `.venv` assumption
and no use of the privileged bridge/R1 interpreter.

Before activation, the host operator must verify these paths still exist and are
readable as UID/GID1000, and that the Python executable has the same resolved
target. A changed Python target requires updating the exact read-only bind;
`ProtectHome=tmpfs` deliberately hides all other home contents. Only the native
CLI checkout, this venv, its exact managed Python distribution and the native
ledger directory are exposed read-only. Bind the ledger directory, not one
SQLite inode: WAL/SHM siblings and atomic replacement must remain coherent.
The CLI checkout is user-owned code executing only with that user's authority.

## Protected configuration

Root prepares `/etc/kernscale-trading/marks.env` mode0600 inside the protected
configuration directory. PID1 reads `EnvironmentFile` before the service's
namespace hides `/etc/kernscale-trading`; the service does not read native.json.
Root copies the already selected account from `/etc/kernscale-trading/native.json`
into `TRADING_ACCOUNT_ID` without printing its value. Required entries:

```dotenv
TRADING_ACCOUNT_ID=<exact privately selected native account>
TRADINGCLI_DATABASE_PATH=/home/jonas/.local/share/tradingagents-paper/paper.sqlite
TRADINGCLI_CHECKOUT=/home/jonas/repositories/products/Trading-CLI
TRADING_MARKS_RECEIPT_PATH=/var/lib/kernscale-trading-marks/latest.json
PAPERTRADE_STRICT_DATA=1
PAPERTRADE_FUND_MODE=1
```

The account placeholder must be replaced before starting: the CLI's historical
default account is not admission evidence. Do not source native `go.env`, bridge
bearer tokens, model credentials or mirror configuration. Public native market
quote calls are the only intended outbound work. The state directory is created
by systemd before filesystem namespace setup, mode0700, owned by `jonas`;
temporary online backups and the receipt stay there. A killed capture may leave
a private temporary snapshot; it must never replace the last completed receipt.

## Host acceptance before enabling the timer

Root installs these two units, runs `systemd-analyze verify` on them, and reloads
the manager. Start only `kernscale-trading-marks.service` for initial acceptance.
Verify actual unprivileged identity, restrictive namespace, receipt mode/owner,
exact account and position coverage, source/market/received timestamps, and that
the authoritative ledger/book is unchanged. Do not publish private receipts or
financial content in logs. Confirm the bridge reads this new receipt via its
protected `TRADING_MARKS_RECEIPT_PATH`; its separate namespace must expose only
the new marks directory read-only. This template does not alter that namespace.

Only after that acceptance enable `kernscale-trading-marks.timer`. It schedules
two minutes after service inactivity; a single systemd oneshot cannot overlap
itself. Runtime is bounded by90seconds plus15seconds termination. There is no
automatic retry loop or account mutation. Disabling this new timer stops future
mark captures without changing native ledger/research services or prior receipts.
Local macOS review cannot qualify Linux namespace behavior, native quote network
availability, or successful production capture; the host operator owns those
checks and activation.
