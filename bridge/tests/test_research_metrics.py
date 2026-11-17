"""Pinned SDK event payload qualification with an offline subprocess only."""
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


@unittest.skipUnless(importlib.util.find_spec('omp_rpc'), 'requires pinned SDK')
class ResearchMetricsTests(unittest.TestCase):
    def run_fixture(self, *, usage=None, context=None, delay='0'):
        from omp_rpc import RpcClient, host_tool
        from bridge.omp_client import stream_turn
        with tempfile.TemporaryDirectory() as directory:
            env = {'OMP_FAKE_SESSION_DIR': directory, 'OMP_FAKE_DELAY': delay, 'OMP_FAKE_ASYNC_DELAY': '1'}
            if usage is not None:
                env['OMP_FAKE_USAGE'] = json.dumps(usage)
            if context is not None:
                env['OMP_FAKE_CONTEXT'] = json.dumps(context)
            tool = host_tool(name='calc', description='Offline arithmetic fixture', parameters={'type': 'object'}, execute=lambda *args: '3')
            events = []
            with RpcClient(command=[sys.executable, str(Path(__file__).with_name('fake_omp.py'))],
                           custom_tools=[tool], max_event_history=8, env=env) as client:
                self.assertEqual(stream_turn(client, 'offline', lambda kind, payload: events.append((kind, payload))), '3')
            return events

    def test_whitelisted_usage_and_changed_context_exclude_private_state(self):
        events = self.run_fixture(usage={'input': 12, 'output': 6, 'cacheRead': 3, 'cacheWrite': 0,
            'totalTokens': 21, 'reasoningTokens': 2, 'cost': {'total': 999},
            'private': 'DO_NOT_EMIT_PRIVATE_MARKER'},
            context={'tokens': 100, 'contextWindow': 4096, 'percent': 2.44140625}, delay='0.1')
        usage = next(payload for kind, payload in events if kind == 'usage')
        self.assertEqual(usage['counts'], {'input': 12, 'output': 6, 'cacheRead': 3, 'cacheWrite': 0, 'totalTokens': 21, 'reasoningTokens': 2})
        self.assertEqual(usage['source'], 'omp_normalized')
        self.assertTrue(usage['available'])
        self.assertEqual(usage['native_session_id'], 'offline-session')
        self.assertEqual(usage['message_id'], 'message-fixture')
        self.assertEqual(usage['response_id'], 'response-fixture')
        self.assertIsNone(usage['cost'])
        contexts = [payload for kind, payload in events if kind == 'context']
        self.assertEqual(len(contexts), 2)  # initial and final; unchanged liveness snapshots are not events
        self.assertEqual([payload['final'] for payload in contexts], [False, True])
        self.assertEqual(contexts[-1]['tokens'], 100)
        self.assertEqual(contexts[-1]['context_window'], 4096)
        self.assertFalse(contexts[-1]['capacity_verified'])
        self.assertEqual(contexts[-1]['source'], 'runtime_estimate')
        selected = [payload for kind, payload in events if kind in ('usage', 'context')]
        self.assertNotIn('DO_NOT_EMIT_PRIVATE_MARKER', json.dumps(selected))
        self.assertNotIn('Authorization', json.dumps(selected))

    def test_absent_or_default_zero_usage_does_not_claim_observed_free_usage(self):
        for raw in (None, {}, {'input': 0, 'output': 0, 'totalTokens': 0, 'cost': {'total': 0}},
                    {'input': -1, 'output': float('inf'), 'totalTokens': True}):
            with self.subTest(raw=raw):
                events = self.run_fixture(usage=raw)
                payload = next(payload for kind, payload in events if kind == 'usage')
                self.assertFalse(payload['available'])
                self.assertIsNone(payload['counts'])
                self.assertIsNone(payload['cost'])
                context = next(payload for kind, payload in events if kind == 'context')
                self.assertFalse(context['available'])
                self.assertIsNone(context['context_window'])
                self.assertIsNone(context['percent'])

    def test_nonpositive_capacity_has_no_fabricated_percentage(self):
        for capacity in (0, -1):
            with self.subTest(capacity=capacity):
                events = self.run_fixture(context={'tokens': 10, 'contextWindow': capacity, 'percent': 99})
                payload = next(payload for kind, payload in events if kind == 'context')
                self.assertEqual(payload['tokens'], 10)
                self.assertIsNone(payload['context_window'])
                self.assertIsNone(payload['percent'])
