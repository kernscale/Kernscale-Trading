"""Read the native paper ledger and saved research without invoking a trading engine."""
import hashlib
import json
import math
import os
import pwd
import re
import sqlite3
import stat
import subprocess
from contextlib import closing
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote
from uuid import NAMESPACE_URL, uuid5

MAX_BYTES = 2_000_000
RUN_ID = re.compile(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\Z')


class PortfolioUnavailable(RuntimeError):
    pass


class ReportNotFound(KeyError):
    pass


def _config(config):
    return os.environ if config is None else config


def _account(config):
    return config.get('TRADING_ACCOUNT_ID', 'global-paper')


def _root(config):
    raw = config.get('TRADING_REPORTS_ROOT') or config.get('TRADINGAGENTS_PAPER_REPORTS')
    if not raw:
        raise PortfolioUnavailable('Native research root is not configured')
    return Path(raw).expanduser().resolve()


def _iso(timestamp=None):
    return datetime.fromtimestamp(timestamp, timezone.utc).isoformat() if timestamp is not None else datetime.now(timezone.utc).isoformat()


def _clean(value):
    if isinstance(value, float) and not math.isfinite(value):
        return None
    if isinstance(value, dict):
        return {key: _clean(item) for key, item in value.items()}
    if isinstance(value, list):
        return [_clean(item) for item in value]
    return value


def _read(root, path):
    """Open every component relative to the trusted root, rejecting symlink races."""
    parts = path.relative_to(root).parts
    if not parts or any(part in ('', '.', '..') for part in parts):
        raise ValueError('Invalid artifact path')
    fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        file_fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
        with os.fdopen(file_fd, 'rb') as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_BYTES:
                raise ValueError('Unavailable or oversized native artifact')
            data = handle.read(MAX_BYTES + 1)
            if len(data) > MAX_BYTES:
                raise ValueError('Oversized native artifact')
            return data
    finally:
        os.close(fd)


def _rows(conn, table, account, required=False):
    # All table identifiers are literals supplied inside this module, never request input.
    exists = conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
    if not exists:
        if required:
            raise PortfolioUnavailable('Native ledger schema is incomplete')
        return []
    return [dict(row) for row in conn.execute(f'SELECT * FROM {table} WHERE account=?', (account,))]


def _saved_marks(config, native, positions, account):
    raw_path = config.get('TRADING_MARKS_RECEIPT_PATH')
    if not raw_path:
        return {}, {'state': 'unconfigured', 'reason': 'No saved native mark receipt is configured.'}
    path = Path(raw_path).expanduser().resolve()
    try:
        raw = _read(path.parent, path)
        receipt = json.loads(raw)
        for key, native_key in [('cash', 'cash'), ('net_funding', 'deposits'), ('realized_pnl', 'realized')]:
            if not math.isclose(float(receipt[key]), float(native[native_key]), rel_tol=0, abs_tol=0.000001):
                raise ValueError('Saved mark receipt no longer reconciles with the native ledger')
        if receipt['position_count'] != len(positions) or len(receipt['positions']) != len(positions):
            raise ValueError('Saved mark position count differs from native ledger')
        mirrored = {row['symbol']: row for row in receipt['positions']}
        observations = {row['symbol']: row for row in receipt['observations']}
        if len(mirrored) != len(positions) or len(observations) != len(positions):
            raise ValueError('Saved mark identities are incomplete or duplicated')
        captured = datetime.fromisoformat(receipt['captured_at'])
        now = datetime.now(timezone.utc)
        if captured.tzinfo is None or captured > now:
            raise ValueError('Invalid saved mark capture timestamp')
        marks = {}
        for position in positions:
            if position['asset_class'] != 'spot' or position['mult'] != 1 or position['qty'] < 0:
                raise ValueError('Native saved mirror supports only long spot positions')
            symbol = position['symbol']
            identity = str(uuid5(NAMESPACE_URL, f'kernscale:paper:{account}:{symbol}'))
            mirror = mirrored[identity]
            observation = observations[symbol]
            price = float(observation['price_usd'])
            observed = datetime.fromisoformat(observation['observed_at'])
            received = datetime.fromisoformat(observation['received_at'])
            if not math.isfinite(price) or price <= 0 or not observation.get('source'):
                raise ValueError('Invalid saved mark price/source')
            if observed.tzinfo is None or received.tzinfo is None or observed > received or received > captured:
                raise ValueError('Invalid saved mark observation timestamps')
            if not math.isclose(float(mirror['quantity']), position['qty'], rel_tol=0, abs_tol=0.00000001) or not math.isclose(float(mirror['price_usd']), price, rel_tol=0, abs_tol=0.000001):
                raise ValueError('Saved mark quantity or price does not reconcile')
            marks[symbol] = {**observation, 'price_usd': price, 'age_seconds': max(0, (now - observed).total_seconds())}
        return marks, {'state': 'reconciled_saved_observations', 'captured_at': receipt['captured_at'],
                       'sha256': hashlib.sha256(raw).hexdigest(), 'currency': 'USD',
                       'reason': 'Saved native mark observations; current market freshness is unverified.'}
    except (OSError, ValueError, TypeError, KeyError, OverflowError, RecursionError):
        return {}, {'state': 'unavailable', 'reason': 'Saved mark receipt is missing, malformed, or no longer reconciles with the native ledger.'}


def portfolio_snapshot(config=None):
    config = _config(config)
    raw = config.get('TRADINGCLI_DATABASE_PATH') or config.get('PAPERTRADE_DB')
    if not raw:
        raise PortfolioUnavailable('Native paper database is not configured')
    path = Path(raw).expanduser().resolve()
    account = _account(config)
    try:
        with closing(sqlite3.connect('file:' + quote(str(path), safe='/') + '?mode=ro', uri=True, timeout=5)) as conn:
            conn.row_factory = sqlite3.Row
            conn.execute('PRAGMA query_only=ON')
            conn.execute('BEGIN')
            row = conn.execute('SELECT name,cash,deposits,realized,created FROM accounts WHERE name=?', (account,)).fetchone()
            if row is None:
                raise PortfolioUnavailable('Configured native paper account is unavailable')
            native = dict(row)
            positions = _rows(conn, 'positions', account, required=True)
            orders = _rows(conn, 'orders', account, required=True)
            cashflows = _rows(conn, 'cashflow', account)
            actions = _rows(conn, 'corporate_actions', account)
            risk = _rows(conn, 'risk_settings', account)
            watchlists = _rows(conn, 'watchlists', account)
            for watchlist in watchlists:
                watchlist['symbols'] = [r[0] for r in conn.execute('SELECT symbol FROM watchlist_symbols WHERE watchlist_id=? ORDER BY position,symbol', (watchlist['id'],))]
    except (sqlite3.Error, OSError) as exc:
        raise PortfolioUnavailable('Native paper ledger cannot be read') from exc
    marks, marks_info = _saved_marks(config, native, positions, account)
    holdings = [{**position, 'signed_qty': position['qty'], 'current_price': None,
                 'market_value': None, 'unrealized_pnl': None, 'quote_asof': None,
                 'quote_source': None, 'stale': True} for position in positions]
    for holding in holdings:
        observation = marks.get(holding['symbol'])
        if observation:
            price = observation['price_usd']
            source = observation.get('source')
            age = observation.get('age_seconds')
            fresh = (marks_info['state'] == 'reconciled_saved_observations'
                     and isinstance(source, str) and bool(source.strip())
                     and observation.get('currency', 'USD') == 'USD'
                     and type(age) in (int, float) and math.isfinite(age) and 0 <= age <= 300
                     and math.isfinite(price) and price > 0)
            holding.update(current_price=price, market_value=holding['qty'] * price,
                           unrealized_pnl=holding['qty'] * (price - holding['avg_cost']),
                           quote_asof=observation['observed_at'], quote_source=observation['source'],
                           quote=observation, currency='USD', valuation_status='saved_observation', stale=not fresh)
    # Native SQLite stores no marks. Do not substitute cost basis or historical closes.
    metrics = {'cash': native['cash'], 'deposits': native['deposits'], 'realized_pnl': native['realized'],
               'equity': None if holdings else native['cash'],
               'unrealized_pnl': None if holdings else 0,
               'total_pnl': None if holdings else native['realized'],
               'day_pnl': None, 'return_pct': None, 'sharpe_ratio': None, 'max_drawdown': None}
    if holdings and all(holding['market_value'] is not None for holding in holdings):
        unrealized = sum(holding['unrealized_pnl'] for holding in holdings)
        metrics.update(equity=native['cash'] + sum(holding['market_value'] for holding in holdings),
                       unrealized_pnl=unrealized, total_pnl=native['realized'] + unrealized)
    activities = [{**order, 'type': 'order'} for order in orders]
    activities += [{**flow, 'type': 'cashflow'} for flow in cashflows]
    activities += [{**action, 'type': 'corporate_action', 'ts': action.get('action_date')} for action in actions]
    activities.sort(key=lambda activity: str(activity.get('ts') or ''), reverse=True)
    market_asof = min((mark['observed_at'] for mark in marks.values()), key=datetime.fromisoformat) if marks else None
    stale = any(holding['stale'] for holding in holdings)
    if marks:
        oldest_age = max(mark['age_seconds'] for mark in marks.values())
        marks_info['reason'] = (f'Saved native observations; oldest observation age {oldest_age:.1f} seconds. '
                                'Fresh valuation requires every holding to have a valid USD observation at most 300 seconds old.')
    if not holdings:
        freshness_reason = 'Cash-only native ledger; market marks are not required. No historical series was reconstructed.'
    elif not stale:
        freshness_reason = 'All holdings have reconciled saved USD observations within 300 seconds. No native observed equity history is available.'
    else:
        freshness_reason = 'Market observations are missing, invalid, unsupported, or older than 300 seconds. No native observed equity history is available.'
    return _clean({'observed_at': _iso(), 'source': 'native-paper-ledger', 'provenance': {'kind': 'native-paper-ledger', 'account': account, 'read_only': True},
                   'accounts': [{**native, **metrics}], 'holdings': holdings, 'activities': activities,
                   'orders': orders, 'cashflows': cashflows, 'corporate_actions': actions, 'watchlists': watchlists,
                   'capabilities': {'available': ['ledger', 'holdings', 'orders', 'cashflows', 'risk', 'saved_reports'],
                                    'conditional': {'market_valuation': marks_info['state']},
                                    'unavailable': ['observed_equity_history', 'day_performance', 'benchmark', 'manual_activity_writes', 'paper_controls']},
                   'metrics': metrics, 'risk': risk[0] if risk else None, 'history': [],
                   'freshness': {'ledger_read_at': _iso(), 'market_asof': market_asof, 'stale': stale,
                                 'marks_status': marks_info['state'] if holdings else 'not_required', 'mark_receipt': marks_info,
                                 'history_status': 'unavailable',
                                 'reason': freshness_reason}})


def _receipts(config):
    root = _root(config)
    account = _account(config)
    rows, unreadable = [], 0
    if not root.is_dir():
        return rows, {'available': False, 'unreadable_receipts': 0}
    for path in sorted(root.glob('20*.json'), reverse=True):
        try:
            raw = _read(root, path)
            row = json.loads(raw)
            if not isinstance(row, dict) or not RUN_ID.fullmatch(str(row.get('id', ''))):
                raise ValueError('Invalid native receipt')
            started = row.get('started_at')
            if isinstance(started, bool) or not isinstance(started, (int, float)) or not math.isfinite(started):
                raise ValueError('Invalid native receipt timestamp')
            day = datetime.fromtimestamp(started, timezone.utc).date().isoformat()
            if path.name != f"{day}-{row['id']}.json":
                raise ValueError('Noncanonical native receipt filename')
            if row.get('account') != account:
                continue
            rows.append((row, hashlib.sha256(raw).hexdigest(), path.name))
        except (OSError, ValueError, TypeError, RecursionError, OverflowError):
            unreadable += 1
    rows.sort(key=lambda item: item[0].get('started_at') if isinstance(item[0].get('started_at'), (int, float)) else 0, reverse=True)
    return rows, {'available': True, 'unreadable_receipts': unreadable}


def _artifact(row, receipt_sha, config):
    root = _root(config)
    identity = row['id']
    for filename, media_type in [('complete_report.md', 'text/markdown'), ('complete_report.html', 'text/html')]:
        try:
            raw = _read(root, root / identity / 'reports' / filename)
            return {'id': identity, 'title': 'Scheduled research · ' + str(row.get('symbol') or 'unselected'),
                    'created_at': _iso(row['started_at']), 'content': raw.decode('utf-8'), 'media_type': media_type,
                    'sha256': hashlib.sha256(raw).hexdigest(), 'receipt_sha256': receipt_sha,
                    'source': 'scheduled-native-research', 'status': row.get('status'),
                    'account': row.get('account'), 'symbol': row.get('symbol')}
        except (OSError, ValueError, UnicodeError):
            continue
    raise ReportNotFound('Saved native report is unavailable')


def _research_outcome(row, has_report):
    status = row.get('status')
    card = row.get('research_card') if isinstance(row.get('research_card'), dict) else {}
    complete = card.get('status') == 'complete' and has_report
    if status == 'failed':
        return 'failed'
    if row.get('error'):
        return 'execution_failed' if status == 'researched' and complete else 'failed'
    if status in ('started', 'interrupted'):
        return 'in_progress' if status == 'started' else 'interrupted'
    if card.get('status') == 'incomplete':
        return 'incomplete'
    if status == 'researched':
        return 'execution_pending'
    return 'complete' if status == 'complete' and complete else 'unverified'


def _unit(value, suffix):
    return isinstance(value, str) and bool(re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.@:-]{0,120}\.' + suffix, value))


def _observer(config):
    native = config.get('TRADING_NATIVE_SERVICE_USER')
    if native is not None and (not isinstance(native, str) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', native)):
        return None
    uid = os.geteuid()
    if uid == 0:
        return ['--machine=' + native + '@.host'] if native and native != 'root' else None
    try:
        current = pwd.getpwuid(uid).pw_name
    except (KeyError, OSError):
        return None
    return [] if native is None or native == current else None


def _show(unit, transport):
    properties = 'ActiveState,SubState,UnitFileState,Result,ExecMainStatus,Unit,NextElapseUSecRealtime'
    keys = ('PATH',) if transport else ('PATH', 'HOME', 'XDG_RUNTIME_DIR', 'DBUS_SESSION_BUS_ADDRESS')
    environment = {key: os.environ[key] for key in keys if key in os.environ}
    environment.update(LC_ALL='C', LANG='C', TZ='UTC')
    try:
        result = subprocess.run(['systemctl', '--user', *transport, 'show', unit, '--property=' + properties, '--no-pager'],
                                shell=False, capture_output=True, text=True, timeout=2, env=environment)
        if result.returncode or len(result.stdout) > 8192:
            return None
        values = dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)
        if values.get('ActiveState') not in ('active', 'inactive', 'activating', 'deactivating', 'failed', 'reloading', 'maintenance'):
            return None
        if not re.fullmatch(r'[a-z-]{1,40}', values.get('SubState', '')):
            return None
        if values.get('UnitFileState') not in ('enabled', 'enabled-runtime', 'disabled', 'static', 'indirect', 'masked', 'masked-runtime', 'linked', 'linked-runtime', 'alias', 'generated', 'transient'):
            return None
        return values
    except (OSError, subprocess.SubprocessError, ValueError, TypeError):
        return None


def _next_run(value):
    match = re.fullmatch(r'[A-Za-z]{3} ([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) UTC', value or '')
    try:
        return datetime.strptime(match[1], '%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc).isoformat() if match else None
    except ValueError:
        return None


def _live_status(config, timer, schedule):
    worker = {'state': 'unverified', 'source': None, 'unit': None, 'active_state': None,
              'sub_state': None, 'unit_file_state': None, 'result': None, 'exec_main_status': None}
    if not _unit(timer, 'timer'):
        return None, worker
    transport = _observer(config)
    if transport is None:
        return None, worker
    observed = _show(timer, transport)
    if observed is None:
        return None, worker
    service = observed.get('Unit')
    if not _unit(service, 'service'):
        return None, worker
    schedule.update(state='observed', source='configured timer and systemctl --user show', timer_unit=timer,
                    active_state=observed['ActiveState'], sub_state=observed['SubState'],
                    unit_file_state=observed['UnitFileState'], next_run=_next_run(observed.get('NextElapseUSecRealtime')))
    observed = _show(service, transport)
    if observed is None:
        return None, worker
    worker.update(state='observed', source='systemctl --user show', unit=service,
                  active_state=observed['ActiveState'], sub_state=observed['SubState'],
                  unit_file_state=observed['UnitFileState'])
    result = observed.get('Result')
    worker['result'] = result if result in ('success', 'exit-code', 'signal', 'timeout', 'core-dump', 'watchdog', 'start-limit-hit', 'resources', 'protocol') else None
    code = observed.get('ExecMainStatus', '')
    worker['exec_main_status'] = int(code) if re.fullmatch(r'[0-9]{1,3}', code) and int(code) <= 255 else None
    active, sub = observed['ActiveState'], observed['SubState']
    running = True if (active == 'active' and sub == 'running') or (active == 'activating' and sub in ('start', 'start-pre', 'start-post')) else None
    if active in ('inactive', 'failed') or (active == 'active' and sub == 'exited'):
        running = False
    return running, worker


def saved_reports(config=None):
    config = _config(config)
    rows, _ = _receipts(config)
    result = []
    for row, checksum, _ in rows:
        try:
            artifact = _artifact(row, checksum, config)
        except ReportNotFound:
            artifact = None
        result.append({'id': row['id'], 'title': 'Scheduled research · ' + str(row.get('symbol') or 'unselected'),
                       'created_at': _iso(row['started_at']), 'account': row.get('account'), 'symbol': row.get('symbol'),
                       'status': row.get('status'), 'rating': row.get('rating'),
                       'research_outcome': _research_outcome(row, artifact is not None),
                       'started_at': row.get('started_at'), 'completed_at': row.get('completed_at'),
                       'reason': row.get('reason'), 'research_purpose': row.get('research_purpose'),
                       'card_status': (row.get('research_card') or {}).get('status') if isinstance(row.get('research_card'), dict) else None,
                       'source': 'scheduled-native-research', 'receipt_sha256': checksum,
                       'has_report': artifact is not None, 'report_sha256': artifact['sha256'] if artifact else None})
    return _clean(result)


def report_artifact(report_id, config=None):
    if not isinstance(report_id, str) or not RUN_ID.fullmatch(report_id):
        raise ReportNotFound('Invalid native report ID')
    config = _config(config)
    rows, _ = _receipts(config)
    for row, checksum, _ in rows:
        if row['id'] == report_id:
            return _artifact(row, checksum, config)
    raise ReportNotFound('Native report was not found')


def service_status(config=None):
    config = _config(config)
    rows, info = _receipts(config)
    root = _root(config)
    schedule = {'state': 'unverified', 'on_calendar': [], 'next_run': None, 'source': None}
    raw_path = config.get('TRADING_SCHEDULE_PATH')
    timer = None
    if raw_path:
        path = Path(raw_path).expanduser().resolve()
        try:
            raw = _read(path.parent, path)
            timer = path.name
            schedule.update(source='configured systemd timer unit', sha256=hashlib.sha256(raw).hexdigest(),
                            on_calendar=[line.split('=', 1)[1].strip() for line in raw.decode().splitlines() if line.startswith('OnCalendar=')])
        except (OSError, ValueError, UnicodeError):
            schedule['state'] = 'unavailable'
    try:
        _read(root, root / 'enabled')
        enabled = True
    except FileNotFoundError:
        enabled = False if info['available'] else None
    except (OSError, ValueError):
        enabled = None
    worker_active, worker = _live_status(config, timer, schedule)
    latest = None
    if rows:
        row, checksum, _ = rows[0]
        latest = {key: row.get(key) for key in ['id', 'status', 'symbol', 'started_at', 'completed_at']}
        try:
            _artifact(row, checksum, config)
            has_report = True
        except ReportNotFound:
            has_report = False
        latest['research_outcome'] = _research_outcome(row, has_report)
    return _clean({'observed_at': _iso(), 'source': 'native-paper-research', 'account': _account(config),
                   **info, 'enabled': enabled, 'worker_active': worker_active, 'worker': worker, 'schedule': schedule,
                   'latest_run': latest,
                   'runs_observed': len(rows), 'note': 'Research outcome, observed worker state, timer state and the native enabled marker are independent; systemd success is not research success.'})
