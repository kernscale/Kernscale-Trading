import tempfile
import unittest
from pathlib import Path
from uuid import uuid4

from fastapi.testclient import TestClient

from bridge.app import create_app
from bridge.store import Conflict, Store
from bridge.tests.test_bridge import FakeExecutor, HEADERS, TOKEN


def question(identifier='0'):
    return {'id': identifier, 'question': 'Which scope?', 'header': 'Scope',
            'options': [{'label': 'First', 'description': ''}, {'label': 'Second', 'description': ''}]}


class ClarificationStoreTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.store = Store(Path(self.directory.name) / 'runs.sqlite')
        self.run, self.request = str(uuid4()), str(uuid4())
        self.body = {'session_id': str(uuid4()), 'prompt': 'offline'}
        self.store.create('1', self.run, self.body)
        self.store.transition('1', self.run, 'running')
        self.payload = {'request_id': self.request, 'tool_call_id': 'ask-call', 'questions': [question(), question('1')]}
        self.store.open_clarification('1', self.run, self.payload)
        self.answers = {'answers': [{'id': '1', 'answer': 'Freeform'}, {'id': '0', 'answer': 'Second'}]}

    def test_api_commit_replay_scope_and_actual_consumption(self):
        app = create_app(self.store, FakeExecutor(), TOKEN)
        self.addCleanup(lambda: app.state.run_pool.shutdown(wait=True))
        client = TestClient(app)
        path = f'/v1/researchers/1/runs/{self.run}/clarifications/{self.request}/answer'
        self.assertEqual(client.post(path, json=self.answers).status_code, 401)
        self.assertEqual(client.post(path.replace('/researchers/1/', '/researchers/2/'), headers=HEADERS, json=self.answers).status_code, 404)
        response = client.post(path, headers=HEADERS, json=self.answers)
        self.assertEqual(response.status_code, 200)
        receipt = response.json()
        self.assertEqual(receipt['status'], 'waiting_for_input')
        self.assertEqual(receipt['clarification']['status'], 'accepted')
        self.assertIsNotNone(receipt['clarification']['answered_at'])
        self.assertIsNone(receipt['clarification']['consumed_at'])
        reordered = {'answers': list(reversed(self.answers['answers']))}
        self.assertEqual(client.post(path, headers=HEADERS, json=reordered).json(), receipt)
        self.assertEqual(client.post(path, headers=HEADERS, json={'answers': [{'id': '0', 'answer': 'Different'}, {'id': '1', 'answer': 'Freeform'}]}).status_code, 409)
        reopened = Store(self.store.path)
        control = reopened.clarification_controls('1', self.run)[0]
        self.assertEqual([answer['id'] for answer in control['answers']], ['0', '1'])
        self.assertFalse(reopened.consume_clarification('1', self.run, {**control, 'answer_digest': 'forged'}))
        self.assertEqual(reopened.get('1', self.run)['status'], 'waiting_for_input')
        self.assertTrue(reopened.consume_clarification('1', self.run, control))
        self.assertFalse(reopened.consume_clarification('1', self.run, control))
        self.assertEqual(self.store.get('1', self.run)['status'], 'running')
        consumed = client.post(path, headers=HEADERS, json=reordered).json()['clarification']
        self.assertEqual(consumed['status'], 'consumed')
        self.assertIsNotNone(consumed['consumed_at'])
        self.assertEqual(sum(event['type'] == 'clarification_consumed' for event in self.store.events('1', self.run)), 1)

    def test_expiry_cancel_and_restart_close_without_defaults_or_reopening(self):
        with self.store.connect() as db:
            db.execute("UPDATE clarifications SET expires_at='2000-01-01T00:00:00+00:00'")
        with self.assertRaises(Conflict):
            self.store.answer_clarification('1', self.run, self.request, self.answers)
        control = self.store.clarification_controls('1', self.run)[0]
        self.assertEqual(control['status'], 'expired')
        self.assertNotIn('answers', control)
        self.assertFalse(self.store.open_clarification('1', self.run, self.payload))
        self.assertEqual(self.store.get('1', self.run)['status'], 'running')
        self.payload['request_id'] = str(uuid4())
        self.store.open_clarification('1', self.run, self.payload)
        self.store.request_cancel('1', self.run)
        with self.assertRaises(Conflict):
            self.store.answer_clarification('1', self.run, self.payload['request_id'], self.answers)
        self.assertFalse(self.store.open_clarification('1', self.run, self.payload))
        self.assertEqual(self.store.get('1', self.run)['status'], 'cancel_requested')
        self.store.reconcile()
        self.assertEqual(self.store.get('1', self.run)['status'], 'interrupted')

    def test_restart_preserves_accepted_answer_but_never_claims_consumption(self):
        self.store.answer_clarification('1', self.run, self.request, self.answers)
        reopened = Store(self.store.path)
        executor = FakeExecutor()
        with TestClient(create_app(reopened, executor, TOKEN)):
            result = reopened.answer_clarification('1', self.run, self.request, self.answers)
        self.assertEqual(result['status'], 'interrupted')
        self.assertEqual(result['clarification']['status'], 'interrupted')
        self.assertIsNotNone(result['clarification']['answered_at'])
        self.assertIsNone(result['clarification']['consumed_at'])
        self.assertFalse(reopened.consume_clarification('1', self.run, {'request_id': self.request, 'answer_digest': 'stale'}))
        self.assertEqual(executor.dispatched, [])

    def test_pending_restart_closes_request_and_rejects_a_late_answer(self):
        reopened = Store(self.store.path)
        reopened.reconcile()
        self.assertEqual(reopened.clarification_controls('1', self.run)[0]['status'], 'interrupted')
        with self.assertRaises(Conflict):
            reopened.answer_clarification('1', self.run, self.request, self.answers)
        self.assertFalse(reopened.open_clarification('1', self.run, self.payload))

    def test_invalid_answers_and_technical_bounds_do_not_mutate(self):
        from bridge.clarifications import Answers, ClarificationRequest
        for answers in ([{'id': '0', 'answer': 'Only one'}], [{'id': '0', 'answer': ' '}, {'id': '1', 'answer': 'x'}],
                        [{'id': '0', 'answer': 'a'}, {'id': '0', 'answer': 'b'}]):
            with self.subTest(answers=answers), self.assertRaises(ValueError):
                self.store.answer_clarification('1', self.run, self.request, {'answers': answers})
        with self.assertRaises(ValueError):
            Answers.model_validate({'answers': [{'id': str(i), 'answer': '\U0001f600' * 8192} for i in range(3)]})
        with self.assertRaises(ValueError):
            ClarificationRequest.model_validate({**self.payload, 'questions': [{**question(str(i)), 'question': '\U0001f600' * 4096,
                'options': [{'label': 'x' * 512, 'description': '\U0001f600' * 2048}] * 4} for i in range(3)]})
        with self.assertRaises(Conflict):
            self.store.create('1', str(uuid4()), self.body)
        self.assertEqual(self.store.clarification_controls('1', self.run), [])
