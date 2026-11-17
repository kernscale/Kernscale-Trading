import copy
import importlib.util
import json
import tempfile
import sys
import unittest
from pathlib import Path
from uuid import uuid4

from fastapi.testclient import TestClient
from pydantic import ValidationError

from bridge.app import RunRequest, create_app
from bridge.attachment_tools import MAX_TEXT_BYTES, build_attachment_tools
from bridge.store import Store


class AttachmentToolsTest(unittest.TestCase):
    def setUp(self):
        self.item = {'id': str(uuid4()), 'name': 'evidence.csv', 'media_type': 'text/csv',
                     'size': 10, 'sha256': 'a' * 64, 'text': 'ticker,value\nA,1'}
        self.events = []
        self.tools = {tool['name']: tool['execute'] for tool in build_attachment_tools(
            [self.item], lambda *event: self.events.append(event), lambda **tool: tool)}

    def test_reads_only_immutable_admitted_text_and_emits_actual_receipt(self):
        self.item['text'] = 'changed'
        receipts = json.loads(self.tools['attachment_list']({}, None))
        self.assertNotIn('text', receipts[0])
        read = json.loads(self.tools['attachment_read']({'id': self.item['id']}, None))
        self.assertEqual(read['text'], 'ticker,value\nA,1')
        self.assertEqual(self.events, [('attachment_reference', receipts[0])])
        read['text'] = 'mutated'
        self.assertEqual(json.loads(self.tools['attachment_read']({'id': self.item['id']}, None))['text'], 'ticker,value\nA,1')

    def test_no_arbitrary_paths_urls_or_unknown_ids(self):
        for args in ({'id': str(uuid4())}, {'id': '/etc/passwd'}, {'id': 'https://example.com'},
                     {'id': self.item['id'], 'path': '/etc/passwd'}):
            with self.subTest(args=args), self.assertRaises(ValueError):
                self.tools['attachment_read'](args, None)
        with self.assertRaises(ValueError):
            self.tools['attachment_list']({'path': '/'}, None)
        self.assertEqual(self.events, [])

    def test_bridge_validates_full_snapshot_bounds_and_uniqueness(self):
        body = {'workspace_id': 'kernscale-trading', 'tenant_id': 'kernscale-trading',
                'session_id': str(uuid4()), 'prompt': 'Research', 'attachments': [self.item]}
        self.assertEqual(len(RunRequest.model_validate(body).attachments), 1)
        for items in ([self.item, self.item], [{**self.item, 'text': '\0'}],
                      [{**self.item, 'text': 'x' * (MAX_TEXT_BYTES + 1)}],
                      [{**self.item, 'text': 'x' * (MAX_TEXT_BYTES // 2 + 1)},
                       {**self.item, 'id': str(uuid4()), 'text': 'x' * (MAX_TEXT_BYTES // 2 + 1)}],
                      [{**self.item, 'path': '/private'}]):
            with self.subTest(items=len(items)), self.assertRaises(ValidationError):
                RunRequest.model_validate({**body, 'attachments': items})

    def test_no_attachment_api_replays_previously_persisted_legacy_body(self):
        class Executor:
            def capabilities(self):
                return {}
            def run(self, *args):
                raise AssertionError('a replay must not execute')
        with tempfile.TemporaryDirectory() as directory:
            store = Store(Path(directory) / 'runs.sqlite')
            body = {'workspace_id': 'kernscale-trading', 'tenant_id': 'kernscale-trading',
                    'session_id': str(uuid4()), 'prompt': 'Legacy', 'model': 'deepseek-v4.1-flash',
                    'workspace': {'thesis': {'content': '', 'version': 1}, 'board': []}}
            run = str(uuid4())
            store.create('1', run, copy.deepcopy(body))
            app = create_app(store=store, executor=Executor(), token='x' * 32)
            client = TestClient(app)
            headers = {'Authorization': 'Bearer ' + 'x' * 32, 'X-Workspace-ID': 'kernscale-trading'}
            response = client.put('/v1/researchers/1/runs/' + run, json=body, headers=headers)
            self.assertEqual(response.status_code, 200, response.text)
            response = client.put('/v1/researchers/1/runs/' + run, json={**body, 'attachments': []}, headers=headers)
            self.assertEqual(response.status_code, 200, response.text)
            app.state.run_pool.shutdown(wait=True)


@unittest.skipUnless(importlib.util.find_spec('omp_rpc'), 'requires pinned SDK')
class AttachmentSdkTest(unittest.TestCase):
    def test_actual_sdk_subprocess_lists_and_reads_only_admitted_snapshot(self):
        from omp_rpc import RpcClient, host_tool
        from bridge.omp_client import stream_turn

        snapshot = {'id': str(uuid4()), 'name': 'fixture-private.csv', 'media_type': 'text/csv',
                    'size': 24, 'sha256': 'b' * 64, 'text': 'Fixture admitted evidence'}
        original = copy.deepcopy(snapshot)
        events = []
        tools = build_attachment_tools([snapshot], lambda kind, payload: events.append((kind, payload)), host_tool)
        snapshot['text'] = 'Changed outside the admitted snapshot'
        fixture = Path(__file__).with_name('fake_omp.py')
        with tempfile.TemporaryDirectory() as directory:
            with RpcClient(command=[sys.executable, str(fixture)], custom_tools=tools,
                           env={'OMP_FAKE_SESSION_DIR': directory, 'OMP_FAKE_ATTACHMENTS': '1'}) as client:
                answer = stream_turn(client, 'Use only the admitted attachment fixture.',
                                     lambda kind, payload: events.append((kind, payload)))
                self.assertFalse(client.protocol_errors)
                self.assertFalse(client.listener_errors)
        self.assertEqual(json.loads(answer), original)
        calls = [payload for kind, payload in events if kind == 'tool_call']
        self.assertEqual([(call['name'], call['args']) for call in calls], [
            ('attachment_list', {}), ('attachment_read', {'id': original['id']})])
        results = {payload['name']: payload for kind, payload in events if kind == 'tool_result'}
        self.assertEqual(set(results), {'attachment_list', 'attachment_read'})
        self.assertTrue(all(result['ok'] for result in results.values()))
        receipt = {key: value for key, value in original.items() if key != 'text'}
        self.assertEqual(json.loads(results['attachment_list']['text']), [receipt])
        self.assertEqual(json.loads(results['attachment_read']['text']), original)
        self.assertEqual([payload for kind, payload in events if kind == 'attachment_reference'], [receipt])
