import hashlib
import json
import sqlite3
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from contextlib import closing
from pathlib import Path
import os
from unittest.mock import patch

from bridge.marks import capture_marks, native_quote_provider
from bridge.portfolio import portfolio_snapshot


class MarkCaptureTests(unittest.TestCase):
    def setUp(self):
        cache = Path(__file__).resolve().parents[2] / '.orchestration' / 'cache'
        cache.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(prefix='marks-tests-', dir=cache)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.database = self.root / 'paper.sqlite'
        self.output = self.root / 'marks' / 'latest.receipt.json'
        self.output.parent.mkdir(mode=0o700)
        with closing(sqlite3.connect(self.database)) as conn, conn:
            conn.executescript('''
                CREATE TABLE accounts(name TEXT,cash REAL,deposits REAL,realized REAL,created TEXT);
                CREATE TABLE positions(account TEXT,symbol TEXT,qty REAL,avg_cost REAL,mult REAL,asset_class TEXT);
                CREATE TABLE orders(account TEXT);
                CREATE TABLE config(key TEXT,value TEXT);
                INSERT INTO accounts VALUES('global-paper',9000,10000,0,'2026-11-01T00:00:00Z');
                INSERT INTO positions VALUES('global-paper','MSFT',2,500,1,'spot');
                INSERT INTO config VALUES('strict_split_block:BLOCKED','operator review required');
            ''')
        now = datetime.now(timezone.utc) - timedelta(seconds=5)
        self.quote = {'last': 510, 'market_timestamp': (now - timedelta(seconds=1)).isoformat(),
                      'received_timestamp': now.isoformat(), 'source': 'synthetic offline fixture',
                      'market_open': True, 'stale': False, 'currency': 'USD'}

    def test_capture_is_atomic_private_ledger_unchanged_and_adapter_reconciles(self):
        before = hashlib.sha256(self.database.read_bytes()).hexdigest()
        receipt = capture_marks(self.database, 'global-paper', self.output, lambda symbol: dict(self.quote))
        self.assertEqual(hashlib.sha256(self.database.read_bytes()).hexdigest(), before)
        self.assertEqual(json.loads(self.output.read_text()), receipt)
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)
        self.assertEqual([path.name for path in self.output.parent.iterdir()], ['latest.receipt.json'])
        data = portfolio_snapshot({'TRADINGCLI_DATABASE_PATH': str(self.database), 'TRADING_ACCOUNT_ID': 'global-paper',
                                   'TRADING_MARKS_RECEIPT_PATH': str(self.output)})
        self.assertEqual(data['metrics']['equity'], 10020)
        self.assertEqual(receipt['observations'][0]['source'], 'synthetic offline fixture')

    def test_quote_failure_preserves_last_valid_receipt(self):
        capture_marks(self.database, 'global-paper', self.output, lambda symbol: dict(self.quote))
        original = self.output.read_bytes()
        def fail(symbol):
            raise ValueError('quote unavailable')
        with self.assertRaises(ValueError):
            capture_marks(self.database, 'global-paper', self.output, fail)
        self.assertEqual(self.output.read_bytes(), original)
        self.assertEqual(len(list(self.output.parent.iterdir())), 1)

    def test_atomic_replace_failure_preserves_prior_receipt_and_cleans_temporary_file(self):
        capture_marks(self.database, 'global-paper', self.output, lambda symbol: dict(self.quote))
        original = self.output.read_bytes()
        with patch('bridge.marks.os.replace', side_effect=OSError('synthetic replacement failure')):
            with self.assertRaises(OSError):
                capture_marks(self.database, 'global-paper', self.output, lambda symbol: dict(self.quote))
        self.assertEqual(self.output.read_bytes(), original)
        self.assertEqual(len(list(self.output.parent.iterdir())), 1)

    def test_book_change_during_capture_rejects_new_observation(self):
        def changed(symbol):
            with closing(sqlite3.connect(self.database)) as conn, conn:
                conn.execute("UPDATE accounts SET cash=8000 WHERE name='global-paper'")
            return dict(self.quote)
        with self.assertRaises(ValueError):
            capture_marks(self.database, 'global-paper', self.output, changed)
        self.assertFalse(self.output.exists())

    def test_unsupported_assets_and_stale_unqualified_or_invalid_quotes_are_rejected(self):
        for changes in [{'stale': True}, {'last': float('nan')}, {'currency': 'EUR'}, {'source': ''}, {'market_timestamp': '2099-01-01T00:00:00Z'}]:
            with self.assertRaises((ValueError, TypeError)):
                capture_marks(self.database, 'global-paper', self.output, lambda symbol: {**self.quote, **changes})
            self.assertFalse(self.output.exists())
        with closing(sqlite3.connect(self.database)) as conn, conn:
            conn.execute("UPDATE positions SET asset_class='future'")
        with self.assertRaises(ValueError):
            capture_marks(self.database, 'global-paper', self.output, lambda symbol: dict(self.quote))

    def test_native_helpers_use_private_backup_and_actual_quote_logic_without_network(self):
        checkout = Path('/Users/jonasknppel/Businesses/Kernscale/repositories/products/Trading-CLI')
        if not (checkout / 'market_quotes.py').is_file():
            self.skipTest('Operator native checkout unavailable for source-path qualification')
        before = hashlib.sha256(self.database.read_bytes()).hexdigest()
        with patch.dict(os.environ, {'PAPERTRADE_FUND_MODE': '0'}):
            with native_quote_provider(checkout, self.database, self.output.parent) as provider:
                copy = Path(os.environ['PAPERTRADE_DB'])
                self.assertNotEqual(copy, self.database)
                import market_quotes
                with self.assertRaises(ValueError):
                    market_quotes._split_block('NEW', detected=True)
                with closing(sqlite3.connect(copy)) as conn, conn:
                    self.assertTrue(conn.execute("SELECT 1 FROM config WHERE key='strict_split_block:NEW'").fetchone())
                now = datetime.now(timezone.utc).timestamp() - 1
                fixture = {'error': [], 'result': {'XXBTZUSD': {'bids': [['50000', '1', now]], 'asks': [['50002', '1', now]]}}}
                with patch.object(market_quotes, '_get', return_value=fixture) as request:
                    quote = provider('BTC-USD')
                self.assertEqual(quote['last'], 50001)
                self.assertEqual(quote['source'], 'Kraken public Depth')
                self.assertTrue(quote['market_open'])
                request.assert_called_once()
            self.assertFalse(copy.exists())
        self.assertEqual(hashlib.sha256(self.database.read_bytes()).hexdigest(), before)


if __name__ == '__main__':
    unittest.main()
