import hashlib
import json
import sqlite3
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path
from datetime import datetime, timedelta, timezone
from uuid import NAMESPACE_URL, uuid5

from bridge.portfolio import (PortfolioUnavailable, ReportNotFound, portfolio_snapshot,
                              report_artifact, saved_reports, service_status)

RUN_ID = '12345678-1234-1234-1234-123456789abc'


class PortfolioTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.db = self.root / 'paper.db'
        with sqlite3.connect(self.db) as conn:
            conn.executescript('''
                CREATE TABLE accounts(name TEXT PRIMARY KEY,cash REAL,deposits REAL,realized REAL,created TEXT);
                CREATE TABLE positions(account TEXT,symbol TEXT,qty REAL,avg_cost REAL,mult REAL,asset_class TEXT,margin REAL);
                CREATE TABLE orders(id INTEGER,account TEXT,symbol TEXT,side TEXT,qty REAL,status TEXT,filled_price REAL,ts TEXT);
                CREATE TABLE cashflow(id INTEGER,account TEXT,ts TEXT,amount REAL);
                CREATE TABLE risk_settings(account TEXT,max_gross_leverage REAL,max_order_notional REAL);
                INSERT INTO accounts VALUES('global-paper',9000,10000,100,'2026-11-01T00:00:00Z');
                INSERT INTO accounts VALUES('private',999,999,0,'2026-11-01T00:00:00Z');
                INSERT INTO positions VALUES('global-paper','MSFT',2,500,1,'spot',0);
                INSERT INTO positions VALUES('private','SECRET',1,999,1,'spot',0);
                INSERT INTO orders VALUES(1,'global-paper','MSFT','buy',2,'filled',500,'2026-11-01T01:00:00Z');
                INSERT INTO cashflow VALUES(1,'global-paper','2026-11-01T00:00:00Z',10000);
                INSERT INTO risk_settings VALUES('global-paper',2,2500);
            ''')
        self.reports = self.root / 'reports'
        self.reports.mkdir()
        self.timer = self.root / 'research.timer'
        self.timer.write_text('[Timer]\nOnCalendar=*-*-* 00/2:10:00 UTC\nPersistent=false\n')
        self.config = {'TRADINGCLI_DATABASE_PATH': str(self.db), 'TRADING_ACCOUNT_ID': 'global-paper',
                       'TRADING_REPORTS_ROOT': str(self.reports), 'TRADING_SCHEDULE_PATH': str(self.timer)}

    def receipt(self, **overrides):
        data = {'id': RUN_ID, 'account': 'global-paper', 'symbol': 'MSFT', 'status': 'complete',
                'started_at': 1794787200, 'completed_at': 1794787300, 'rating': 'Hold', **overrides}
        day = datetime.fromtimestamp(data['started_at'], timezone.utc).date().isoformat()
        path = self.reports / f"{day}-{data['id']}.json"
        path.write_text(json.dumps(data))
        report = self.reports / RUN_ID / 'reports' / 'complete_report.md'
        report.parent.mkdir(parents=True, exist_ok=True)
        report.write_text('# Actual saved research\nHold, zero target weight.')
        return path, report

    def test_snapshot_is_scoped_read_only_and_does_not_invent_marks_or_history(self):
        before = hashlib.sha256(self.db.read_bytes()).hexdigest()
        data = portfolio_snapshot(self.config)
        self.assertEqual(data['accounts'][0]['cash'], 9000)
        self.assertEqual([p['symbol'] for p in data['holdings']], ['MSFT'])
        self.assertIsNone(data['metrics']['equity'])
        self.assertIsNone(data['holdings'][0]['current_price'])
        self.assertEqual(data['history'], [])
        self.assertEqual(data['risk']['max_order_notional'], 2500)
        self.assertEqual(data['activities'][0]['account'], 'global-paper')
        self.assertEqual(hashlib.sha256(self.db.read_bytes()).hexdigest(), before)
        self.assertEqual({p.name for p in self.root.iterdir()}, {'paper.db', 'reports', 'research.timer'})

    def test_cash_only_account_has_known_equity_but_no_fabricated_return_series(self):
        with sqlite3.connect(self.db) as conn:
            conn.execute("DELETE FROM positions WHERE account='global-paper'")
        data = portfolio_snapshot(self.config)
        self.assertEqual(data['metrics']['equity'], 9000)
        self.assertEqual(data['history'], [])
        self.assertIsNone(data['metrics']['sharpe_ratio'])
        self.assertFalse(data['freshness']['stale'])

    def test_saved_native_marks_reconcile_and_remain_labelled_as_saved(self):
        identity = str(uuid5(NAMESPACE_URL, 'kernscale:paper:global-paper:MSFT'))
        receipt = {'captured_at': '2026-11-01T02:00:00+00:00', 'cash': 9000, 'net_funding': 10000,
                   'realized_pnl': 100, 'position_count': 1,
                   'positions': [{'symbol': identity, 'quantity': 2, 'price_usd': 510}],
                   'observations': [{'symbol': 'MSFT', 'price_usd': 510,
                                     'observed_at': '2026-11-01T01:59:00+00:00',
                                     'received_at': '2026-11-01T01:59:01+00:00', 'source': 'native saved observation'}]}
        path = self.root / 'import.receipt.json'
        path.write_text(json.dumps(receipt))
        config = {**self.config, 'TRADING_MARKS_RECEIPT_PATH': str(path)}
        data = portfolio_snapshot(config)
        self.assertEqual(data['metrics']['equity'], 10020)
        self.assertEqual(data['metrics']['unrealized_pnl'], 20)
        self.assertEqual(data['holdings'][0]['currency'], 'USD')
        self.assertTrue(data['holdings'][0]['stale'])
        self.assertEqual(data['holdings'][0]['quote_source'], 'native saved observation')
        self.assertEqual(data['freshness']['mark_receipt']['state'], 'reconciled_saved_observations')
        with sqlite3.connect(self.db) as conn:
            conn.execute("UPDATE accounts SET cash=8000 WHERE name='global-paper'")
        changed = portfolio_snapshot(config)
        self.assertIsNone(changed['metrics']['equity'])
        self.assertEqual(changed['freshness']['mark_receipt']['state'], 'unavailable')

    def mark_config(self, *, age=60, source='verified native feed', currency='USD'):
        now = datetime.now(timezone.utc)
        observed = now - timedelta(seconds=age)
        receipt = {'captured_at': now.isoformat(), 'cash': 9000, 'net_funding': 10000,
                   'realized_pnl': 100, 'position_count': 1,
                   'positions': [{'symbol': str(uuid5(NAMESPACE_URL, 'kernscale:paper:global-paper:MSFT')),
                                  'quantity': 2, 'price_usd': 510}],
                   'observations': [{'symbol': 'MSFT', 'price_usd': 510, 'currency': currency,
                                     'observed_at': observed.isoformat(),
                                     'received_at': (observed + timedelta(seconds=1)).isoformat(), 'source': source}]}
        path = self.root / 'fresh-marks.json'
        path.write_text(json.dumps(receipt))
        return {**self.config, 'TRADING_MARKS_RECEIPT_PATH': str(path)}, receipt, path

    def test_fresh_reconciled_observations_are_eligible_without_fabricated_history(self):
        config, receipt, _ = self.mark_config()
        data = portfolio_snapshot(config)
        self.assertFalse(data['freshness']['stale'])
        self.assertFalse(data['holdings'][0]['stale'])
        self.assertEqual(data['freshness']['market_asof'], receipt['observations'][0]['observed_at'])
        self.assertLessEqual(data['holdings'][0]['quote']['age_seconds'], 300)
        self.assertNotIn('unverified', data['freshness']['mark_receipt']['reason'])
        self.assertEqual(data['history'], [])

    def test_old_missing_unsupported_or_invalid_source_marks_remain_stale(self):
        for overrides in ({'age': 600}, {'source': ' '}, {'source': 123}, {'currency': 'EUR'}):
            config, _, _ = self.mark_config(**overrides)
            data = portfolio_snapshot(config)
            with self.subTest(overrides=overrides):
                self.assertTrue(data['freshness']['stale'])
                self.assertTrue(data['holdings'][0]['stale'])
                self.assertEqual(data['history'], [])
        config, receipt, path = self.mark_config()
        receipt['observations'] = []
        path.write_text(json.dumps(receipt))
        self.assertTrue(portfolio_snapshot(config)['freshness']['stale'])
        config, _, _ = self.mark_config()
        with sqlite3.connect(self.db) as conn:
            conn.execute("UPDATE positions SET asset_class='future' WHERE account='global-paper'")
        self.assertTrue(portfolio_snapshot(config)['freshness']['stale'])

    def test_exact_five_minute_boundary_and_invalid_prices(self):
        config, receipt, path = self.mark_config(age=300)
        current = datetime.fromisoformat(receipt['captured_at'])

        class FrozenDatetime(datetime):
            @classmethod
            def now(cls, tz=None):
                return current

        with patch('bridge.portfolio.datetime', FrozenDatetime):
            self.assertFalse(portfolio_snapshot(config)['freshness']['stale'])
            receipt['observations'][0]['observed_at'] = (current - timedelta(seconds=300.001)).isoformat()
            path.write_text(json.dumps(receipt))
            self.assertTrue(portfolio_snapshot(config)['freshness']['stale'])
        for price in (0, -1, float('nan'), float('inf')):
            config, receipt, path = self.mark_config()
            receipt['observations'][0]['price_usd'] = price
            path.write_text(json.dumps(receipt))
            with self.subTest(price=price):
                self.assertTrue(portfolio_snapshot(config)['freshness']['stale'])
                self.assertEqual(portfolio_snapshot(config)['history'], [])

    def test_mixed_observation_ages_use_oldest_actual_timestamp(self):
        config, receipt, path = self.mark_config()
        older = (datetime.now(timezone.utc) - timedelta(seconds=600)).isoformat()
        with sqlite3.connect(self.db) as conn:
            conn.execute("INSERT INTO positions VALUES('global-paper','AAPL',1,100,1,'spot',0)")
        receipt['position_count'] = 2
        receipt['positions'].append({'symbol': str(uuid5(NAMESPACE_URL, 'kernscale:paper:global-paper:AAPL')),
                                     'quantity': 1, 'price_usd': 110})
        receipt['observations'].append({'symbol': 'AAPL', 'price_usd': 110, 'currency': 'USD',
                                        'observed_at': older, 'received_at': older, 'source': 'verified native feed'})
        path.write_text(json.dumps(receipt))
        data = portfolio_snapshot(config)
        self.assertTrue(data['freshness']['stale'])
        self.assertEqual(data['freshness']['market_asof'], older)
        self.assertFalse(data['holdings'][0]['stale'])
        self.assertTrue(data['holdings'][1]['stale'])
        self.assertEqual(data['history'], [])

    def test_missing_database_does_not_create_one(self):
        path = self.root / 'missing.sqlite'
        with self.assertRaises(PortfolioUnavailable):
            portfolio_snapshot({**self.config, 'TRADINGCLI_DATABASE_PATH': str(path)})
        self.assertFalse(path.exists())

    def test_receipt_report_replay_is_stable_and_checksums_match_native_bytes(self):
        receipt, report = self.receipt()
        first = saved_reports(self.config)
        self.assertEqual(first, saved_reports(self.config))
        self.assertEqual(first[0]['id'], RUN_ID)
        artifact = report_artifact(RUN_ID, self.config)
        self.assertEqual(artifact['content'], report.read_text())
        self.assertEqual(artifact['sha256'], hashlib.sha256(report.read_bytes()).hexdigest())
        self.assertEqual(artifact['receipt_sha256'], hashlib.sha256(receipt.read_bytes()).hexdigest())
        self.assertEqual(artifact, report_artifact(RUN_ID, self.config))

    def test_traversal_and_symlink_artifacts_are_denied(self):
        _, report = self.receipt()
        for identity in ['../paper.db', '%2e%2e%2fpaper.db', '/etc/passwd', RUN_ID + '/../../paper.db']:
            with self.assertRaises(ReportNotFound):
                report_artifact(identity, self.config)
        report.unlink()
        report.symlink_to(self.db)
        with self.assertRaises(ReportNotFound):
            report_artifact(RUN_ID, self.config)

    def test_other_account_receipts_are_not_visible(self):
        self.receipt(account='private')
        self.assertEqual(saved_reports(self.config), [])
        with self.assertRaises(ReportNotFound):
            report_artifact(RUN_ID, self.config)

    def test_noncanonical_receipt_and_symlink_directory_are_denied(self):
        receipt, report = self.receipt()
        receipt.rename(self.reports / '2026-11-16-fake.json')
        self.assertEqual(saved_reports(self.config), [])
        self.assertEqual(service_status(self.config)['unreadable_receipts'], 1)
        receipt, report = self.receipt()
        outside = self.root / 'outside'
        report.parent.rename(outside)
        report.parent.symlink_to(outside, target_is_directory=True)
        with self.assertRaises(ReportNotFound):
            report_artifact(RUN_ID, self.config)

    def test_status_reads_schedule_without_mutating_native_enabled_marker(self):
        (self.reports / 'enabled').write_text('paper research enabled\n')
        self.receipt()
        status = service_status(self.config)
        self.assertTrue(status['enabled'])
        self.assertEqual(status['schedule']['on_calendar'], ['*-*-* 00/2:10:00 UTC'])
        self.assertIsNone(status['worker_active'])
        self.assertEqual((self.reports / 'enabled').read_text(), 'paper research enabled\n')


if __name__ == '__main__':
    unittest.main()
