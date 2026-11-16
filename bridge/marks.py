"""Operator-only native mark capture. No Ghostfolio calls or authoritative ledger writes."""
import argparse
import importlib
import json
import math
import os
import sqlite3
import sys
import tempfile
from contextlib import closing, contextmanager
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import quote
from uuid import NAMESPACE_URL, uuid5


def _number(value):
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
        raise ValueError('Invalid native numeric observation')
    return float(value)


def _timestamp(value):
    stamp = datetime.fromisoformat(value)
    if stamp.tzinfo is None or stamp.timestamp() <= 0 or stamp > datetime.now(timezone.utc):
        raise ValueError('Invalid or future native observation timestamp')
    return stamp.astimezone(timezone.utc)


def _connect(database):
    conn = sqlite3.connect('file:' + quote(str(database), safe='/') + '?mode=ro', uri=True, timeout=5)
    conn.execute('PRAGMA query_only=ON')
    return conn


def _book(database, account):
    with closing(_connect(database)) as conn:
        conn.execute('BEGIN')
        row = conn.execute('SELECT cash,deposits,realized FROM accounts WHERE name=?', (account,)).fetchone()
        if row is None:
            raise ValueError('Configured native account is unavailable')
        positions = conn.execute('SELECT symbol,qty,avg_cost,mult,asset_class FROM positions WHERE account=? AND qty!=0 ORDER BY symbol', (account,)).fetchall()
        admissions = conn.execute("SELECT key,value FROM config WHERE key LIKE 'strict_asset:%' OR key LIKE 'strict_split_block:%' ORDER BY key").fetchall()
        return row, positions, admissions


def _observation(raw):
    price = _number(raw['last'])
    observed = _timestamp(raw['market_timestamp'])
    received = _timestamp(raw['received_timestamp'])
    if price <= 0 or observed > received or raw.get('currency') != 'USD' or not isinstance(raw.get('source'), str) or not raw['source'].strip():
        raise ValueError('Invalid native USD mark/source/timestamp ordering')
    if raw.get('unavailable') or (not raw.get('valuation_asof') and (raw.get('market_open') is not True or raw.get('stale') is not False)):
        raise ValueError('Fresh native observation or validated completed-session valuation required')
    if raw.get('valuation_asof'):
        valuation = _timestamp(raw['valuation_asof'])
        if observed > valuation or valuation > received:
            raise ValueError('Invalid completed-session valuation timestamp')
    return price, observed.isoformat(), received.isoformat()


def capture_marks(database, account, output, get_quote):
    """Collect all positions or keep the prior receipt. get_quote must be an admitted native provider."""
    database = Path(database).expanduser().resolve()
    output = Path(output).expanduser()
    if output.is_symlink():
        raise ValueError('Refusing a symlink receipt target')
    output = output.parent.resolve() / output.name
    if output in [database, Path(str(database) + '-wal'), Path(str(database) + '-shm')]:
        raise ValueError('Receipt output must be separate from the native ledger')
    before = _book(database, account)
    row, positions, _ = before
    cash, funding, realized = map(_number, row)
    observations, mirrored = [], []
    for symbol, qty, avg, multiplier, kind in positions:
        qty, avg, multiplier = map(_number, [qty, avg, multiplier])
        if qty <= 0 or multiplier != 1 or kind != 'spot' or avg < 0:
            raise ValueError('Only native long spot positions with multiplier 1 are qualified')
        raw = get_quote(symbol)
        price, observed, received = _observation(raw)
        observation = {'symbol': symbol, 'price_usd': price, 'observed_at': observed,
                       'received_at': received, 'source': raw['source'], 'currency': 'USD'}
        for key in ['delay_seconds', 'valuation_asof', 'valuation_session', 'local_currency', 'local_price', 'fx_rate', 'fx_timestamp']:
            if raw.get(key) is not None:
                observation[key] = raw[key]
        observations.append(observation)
        mirrored.append({'symbol': str(uuid5(NAMESPACE_URL, f'kernscale:paper:{account}:{symbol}')),
                         'quantity': qty, 'price_usd': price})
    if _book(database, account) != before:
        raise ValueError('Native ledger changed during mark capture; prior receipt retained')
    receipt = {'captured_at': datetime.now(timezone.utc).isoformat(), 'cash': cash, 'net_funding': funding,
               'realized_pnl': realized, 'observations': observations, 'position_count': len(positions),
               'positions': mirrored, 'source': 'native-independent-mark-capture', 'account': account,
               'history': 'Actual native observations only; missing history is not backfilled'}
    encoded = json.dumps(receipt, ensure_ascii=False, allow_nan=False, indent=2).encode()
    if not output.parent.is_dir():
        raise ValueError('Create the private mark receipt directory before capture')
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=output.parent, prefix='.marks-', delete=False) as handle:
            temporary = Path(handle.name)
            os.fchmod(handle.fileno(), 0o600)
            handle.write(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, output)
        directory = os.open(output.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
    return receipt


@contextmanager
def native_quote_provider(checkout, database, temporary_parent, account='global-paper'):
    """Dedicated CLI process only: native diagnostics may write solely to a private online backup."""
    checkout = Path(checkout).expanduser().resolve()
    database = Path(database).expanduser().resolve()
    for filename in ['market_risk.py', 'market_quotes.py', 'market_assets.py', 'market_world.py']:
        if not (checkout / filename).is_file():
            raise ValueError('Trusted native quote checkout is incomplete')
    with tempfile.TemporaryDirectory(prefix='.native-marks-', dir=temporary_parent) as temporary:
        os.chmod(temporary, 0o700)
        snapshot = Path(temporary) / 'native.sqlite'
        with closing(_connect(database)) as source, closing(sqlite3.connect(snapshot)) as target:
            source.backup(target)
        os.chmod(snapshot, 0o600)
        baseline = _book(snapshot, account)
        old_database = os.environ.get('PAPERTRADE_DB')
        old_path = sys.path[:]
        os.environ['PAPERTRADE_DB'] = str(snapshot)
        sys.path.insert(0, str(checkout))
        try:
            risk = importlib.import_module('market_risk')
            for name in ['market_risk', 'market_quotes', 'market_assets', 'market_world']:
                module = importlib.import_module(name)
                if Path(module.__file__).resolve().parent != checkout:
                    raise ValueError('Native module origin differs from the configured trusted checkout')
            def get_quote(symbol):
                if _book(database, account) != baseline:
                    raise ValueError('Native book/admission changed after the private snapshot')
                return risk.mark(symbol)
            yield get_quote
        finally:
            sys.path[:] = old_path
            if old_database is None:
                os.environ.pop('PAPERTRADE_DB', None)
            else:
                os.environ['PAPERTRADE_DB'] = old_database


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database', default=os.getenv('TRADINGCLI_DATABASE_PATH') or os.getenv('PAPERTRADE_DB'), required=not (os.getenv('TRADINGCLI_DATABASE_PATH') or os.getenv('PAPERTRADE_DB')))
    parser.add_argument('--account', default=os.getenv('TRADING_ACCOUNT_ID', 'global-paper'))
    parser.add_argument('--output', default=os.getenv('TRADING_MARKS_RECEIPT_PATH'), required=not os.getenv('TRADING_MARKS_RECEIPT_PATH'))
    parser.add_argument('--native-checkout', default=os.getenv('TRADINGCLI_CHECKOUT'), required=not os.getenv('TRADINGCLI_CHECKOUT'))
    args = parser.parse_args(argv)
    os.umask(0o077)
    try:
        with native_quote_provider(args.native_checkout, args.database, Path(args.output).expanduser().parent, args.account) as provider:
            receipt = capture_marks(args.database, args.account, args.output, provider)
        print(f"Captured {receipt['position_count']} genuine native position marks; ledger unchanged")
        return 0
    except (OSError, ValueError, TypeError, KeyError, sqlite3.Error) as error:
        print(f'Mark capture failed ({type(error).__name__}); prior valid receipt retained.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
