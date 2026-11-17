import tempfile
import os
import threading
from unittest.mock import patch
import unittest
from pathlib import Path
from uuid import uuid4

from fastapi.testclient import TestClient

from bridge.app import create_app
from bridge.executor import IsolatedExecutor, IsolationUnavailable
from bridge.store import Conflict, Store

TOKEN = 'offline-service-token-' + 'x' * 32
HEADERS = {'Authorization': 'Bearer ' + TOKEN, 'X-Workspace-ID': 'kernscale-trading'}


class FakeExecutor:
    """Deterministic test seam; never selectable through HTTP or configuration."""
    def __init__(self):
        self.dispatched = []
        self.cancelled = []

    def capabilities(self):
        return {'available': False, 'reason': 'offline fixture'}

    def run(self, researcher, run_id, body):
        self.dispatched.append(run_id)

    def cancel(self, run_id):
        self.cancelled.append(run_id)


class BridgeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        isolated_environment = patch.dict(os.environ, {"TRADING_BRIDGE_IDENTITIES": self.directory.name + "/not-admitted.json"})
        isolated_environment.start()
        self.addCleanup(isolated_environment.stop)
        self.addCleanup(self.directory.cleanup)
        self.store = Store(Path(self.directory.name) / 'runs.sqlite')
        self.executor = FakeExecutor()
        self.app = create_app(self.store, self.executor, TOKEN)
        self.addCleanup(lambda: self.app.state.run_pool.shutdown(wait=True))
        self.client = TestClient(self.app)
        self.run = str(uuid4())
        self.url = '/v1/researchers/1/runs/' + self.run
        self.body = {'workspace_id': 'kernscale-trading', 'tenant_id': 'kernscale-trading',
                     'session_id': str(uuid4()), 'prompt': 'Research a company', 'model': 'deepseek-v4.1-flash'}

    def create(self):
        return self.client.put(self.url, headers=HEADERS, json=self.body)

    def test_auth_and_scope_denied(self):
        self.assertEqual(self.client.put(self.url, json=self.body).status_code, 401)
        self.assertEqual(self.client.put(self.url, headers={**HEADERS, 'X-Workspace-ID': 'other'}, json=self.body).status_code, 403)
        self.assertEqual(self.client.put(self.url, headers=HEADERS, json={**self.body, 'tenant_id': 'other'}).status_code, 403)

    def test_replay_and_changed_payload(self):
        self.assertEqual(self.create().status_code, 200)
        self.assertEqual(self.create().status_code, 200)
        self.assertEqual(self.client.put(self.url, headers=HEADERS, json={**self.body, 'prompt': 'changed'}).status_code, 409)
        self.assertEqual(len(self.executor.dispatched), 1)

    def test_researcher_and_session_ownership(self):
        self.create()
        other = self.url.replace('/researchers/1/', '/researchers/2/')
        self.assertEqual(self.client.get(other, headers=HEADERS).status_code, 404)
        self.assertEqual(self.client.get(other + '/events', headers=HEADERS).status_code, 404)
        self.assertEqual(self.client.post(other + '/cancel', headers=HEADERS).status_code, 404)
        self.assertEqual(self.client.put(other.replace(self.run, str(uuid4())), headers=HEADERS, json=self.body).status_code, 404)

    def test_session_single_flight(self):
        self.create()
        self.assertEqual(self.client.put(self.url.replace(self.run, str(uuid4())), headers=HEADERS, json=self.body).status_code, 409)

    def test_durable_cursor_replay(self):
        self.create()
        self.store.event(self.run, 'source', {'url': 'https://example.com', 'as_of': '2026-11-16'})
        first = self.client.get(self.url + '/events', headers=HEADERS).json()['events']
        reopened = Store(self.store.path)
        replay = reopened.events('1', self.run, first[0]['id'])
        self.assertEqual([item['type'] for item in replay], ['source'])
        self.assertEqual(replay[0]['payload']['as_of'], '2026-11-16')
        self.assertEqual(reopened.events('1', self.run, replay[0]['id']), [])

    def test_cancel_is_requested_until_confirmed(self):
        self.create()
        response = self.client.post(self.url + '/cancel', headers=HEADERS).json()
        self.assertEqual(response['status'], 'cancel_requested')
        self.assertEqual(self.store.transition('1', self.run, 'running'), 'cancel_requested')
        self.store.transition('1', self.run, 'cancelled')
        self.assertEqual(self.store.transition('1', self.run, 'completed'), 'cancelled')

    def test_cancel_before_admission_returns_durable_idempotent_receipt(self):
        expected = {'id': self.run, 'status': 'cancelled'}
        response = self.client.post(self.url + '/cancel', headers=HEADERS)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), expected)
        self.assertEqual(self.client.post(self.url + '/cancel', headers=HEADERS).json(), expected)
        # Store connections close after each operation; reopen and start a fresh coordinator.
        self.app.state.run_pool.shutdown(wait=True)
        reopened = Store(self.store.path)
        restarted_executor = FakeExecutor()
        restarted = create_app(reopened, restarted_executor, TOKEN)
        with TestClient(restarted) as client:
            self.assertEqual(client.post(self.url + '/cancel', headers=HEADERS).json(), expected)
            self.assertEqual(client.put(self.url, headers=HEADERS, json=self.body).status_code, 409)
            self.assertEqual(client.get(self.url, headers=HEADERS).status_code, 404)
            self.assertEqual(client.get(self.url + '/events', headers=HEADERS).status_code, 404)
        self.assertEqual(restarted_executor.dispatched, [])
        self.assertEqual(restarted_executor.cancelled, [])
        with reopened.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM runs').fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM sessions').fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM events').fetchone()[0], 0)

    def test_cancel_before_put_prevents_late_executor_submission(self):
        self.client.post(self.url + '/cancel', headers=HEADERS)
        response = self.create()
        self.app.state.run_pool.shutdown(wait=True)
        self.assertEqual(self.executor.dispatched, [])
        self.assertEqual(response.status_code, 409)

    def test_pre_admission_cancel_scope_and_positive_researcher(self):
        other = self.url.replace('/researchers/1/', '/researchers/2/')
        invalid = self.url.replace('/researchers/1/', '/researchers/0/')
        self.assertEqual(self.client.post(invalid + '/cancel', headers=HEADERS).status_code, 403)
        self.assertEqual(self.client.post(self.url + '/cancel', headers=HEADERS).status_code, 200)
        self.assertEqual(self.client.post(other + '/cancel', headers=HEADERS).status_code, 404)
        self.assertEqual(self.client.put(other, headers=HEADERS, json=self.body).status_code, 404)
        self.assertEqual(self.client.post(self.url + '/cancel', headers=HEADERS).json()['status'], 'cancelled')
        with self.store.connect() as db:
            rows = db.execute('SELECT researcher FROM pre_admission_cancellations').fetchall()
        self.assertEqual([row['researcher'] for row in rows], ['1'])

    def test_store_rejects_invalid_cancellation_identity_without_receipt(self):
        for researcher in ('0', '-1', '', 'other', '\u0661', 1):
            with self.subTest(researcher=researcher), self.assertRaises(ValueError):
                self.store.request_cancel(researcher, self.run)
        self.assertEqual(self.client.post(self.url.replace(self.run, 'not-a-uuid') + '/cancel', headers=HEADERS).status_code, 422)
        with self.store.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM pre_admission_cancellations').fetchone()[0], 0)

    def test_pre_admission_receipt_does_not_bind_or_reserve_session(self):
        self.store.request_cancel('1', self.run)
        with self.assertRaises(Conflict):
            self.store.create('1', self.run, self.body)
        with self.assertRaises(KeyError):
            self.store.request_cancel('2', self.run)
        with self.assertRaises(KeyError):
            self.store.create('2', self.run, self.body)
        # Only the run UUID is terminal: an independent run can still use the session.
        row, created = self.store.create('2', str(uuid4()), self.body)
        self.assertTrue(created)
        self.assertEqual(row['researcher'], '2')

    def test_admission_before_cancel_keeps_actual_run_pending(self):
        self.assertEqual(self.create().status_code, 200)
        other = self.url.replace('/researchers/1/', '/researchers/2/')
        self.assertEqual(self.client.post(other + '/cancel', headers=HEADERS).status_code, 404)
        self.assertEqual(self.store.get('1', self.run)['status'], 'queued')
        for _ in range(2):
            self.assertEqual(self.client.post(self.url + '/cancel', headers=HEADERS).json()['status'], 'cancel_requested')
        self.assertEqual(self.create().json()['status'], 'cancel_requested')
        self.app.state.run_pool.shutdown(wait=True)
        self.assertEqual(self.executor.dispatched, [self.run])
        self.assertEqual(self.executor.cancelled, [self.run, self.run])
        with self.store.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM pre_admission_cancellations').fetchone()[0], 0)

    def test_restart_interrupts_without_replay(self):
        self.create()
        self.store.reconcile()
        self.assertEqual(self.store.get('1', self.run)['status'], 'interrupted')
        self.assertEqual(len(self.executor.dispatched), 1)

    def test_arbitrary_model_and_rpc_rejected(self):
        for extra in ({'model': 'arbitrary'}, {'command': 'bash'}, {'model_url': 'http://internal'}):
            self.assertEqual(self.client.put(self.url, headers=HEADERS, json={**self.body, **extra}).status_code, 422)

    def test_real_executor_fails_closed(self):
        executor = IsolatedExecutor(self.store)
        with self.assertRaises((IsolationUnavailable, FileNotFoundError)):
            executor.admission('1')
        self.create()
        executor.run('1', self.run, self.body)
        self.assertEqual(self.store.get('1', self.run)['status'], 'failed')
        text = str(self.store.events('1', self.run))
        self.assertNotIn(TOKEN, text)

    def test_terminal_run_pages_drain_all_events(self):
        self.create()
        with self.store.connect() as db:
            for index in range(501):
                self.store._event(db, self.run, 'token', {'text': str(index)})
        self.store.transition('1', self.run, 'completed')
        first = self.client.get(self.url + '/events', headers=HEADERS).json()
        self.assertEqual(first['status'], 'completed')
        self.assertTrue(first['has_more'])
        self.assertEqual(len(first['events']), 500)
        second = self.client.get(self.url + '/events?after=' + str(first['next_cursor']), headers=HEADERS).json()
        self.assertFalse(second['has_more'])
        all_events = first['events'] + second['events']
        self.assertEqual(len(all_events), 503)
        self.assertEqual(len({event['id'] for event in all_events}), 503)
        self.assertEqual(all_events[-1]['payload']['status'], 'completed')

    def test_hardware_capacity_queues_distinct_sessions(self):
        entered = threading.Event()
        release = threading.Event()
        second_entered = threading.Event()
        store = self.store

        class BlockingExecutor(FakeExecutor):
            def run(self, researcher, run_id, body):
                store.transition(researcher, run_id, 'running')
                if not entered.is_set():
                    entered.set()
                    release.wait(3)
                else:
                    second_entered.set()
                store.transition(researcher, run_id, 'completed')

        with patch.dict(os.environ, {'TRADING_BRIDGE_WORKERS': '1'}):
            app = create_app(self.store, BlockingExecutor(), TOKEN)
        try:
            client = TestClient(app)
            client.put(self.url, headers=HEADERS, json=self.body)
            self.assertTrue(entered.wait(2))
            other_run = str(uuid4())
            other = self.url.replace(self.run, other_run)
            client.put(other, headers=HEADERS, json={**self.body, 'session_id': str(uuid4())})
            self.assertEqual(store.get('1', other_run)['status'], 'queued')
            self.assertFalse(second_entered.is_set())
            release.set()
            self.assertTrue(second_entered.wait(2))
        finally:
            release.set()
            app.state.run_pool.shutdown(wait=True)

    def test_workspace_snapshot_is_typed_and_part_of_immutable_hash(self):
        self.create()
        changed = {**self.body, 'workspace': {'thesis': {'content': 'Changed thesis', 'version': 1}, 'board': []}}
        self.assertEqual(self.client.put(self.url, headers=HEADERS, json=changed).status_code, 409)
        bad = {**self.body, 'workspace': {'thesis': {'content': 'Bad', 'version': 0}, 'board': []}}
        self.assertEqual(self.client.put(self.url.replace(self.run, str(uuid4())), headers=HEADERS, json=bad).status_code, 422)

    def test_singleton_prevents_live_run_reconciliation(self):
        with TestClient(create_app(self.store, self.executor, TOKEN)):
            self.create()
            with self.assertRaises(BlockingIOError):
                with TestClient(create_app(self.store, self.executor, TOKEN)):
                    pass
            self.assertEqual(self.store.get('1', self.run)['status'], 'queued')


if __name__ == '__main__':
    unittest.main()
