"""Actual pinned SDK with an offline subprocess; no model/provider requests."""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


@unittest.skipUnless(importlib.util.find_spec('omp_rpc'), 'requires pinned SDK')
class StreamTests(unittest.TestCase):
    def client(self, directory, **env):
        from omp_rpc import RpcClient, host_tool
        tool = host_tool(name='calc', description='Offline fixed arithmetic fixture',
            parameters={'type': 'object'}, execute=lambda args, context: '3')
        return RpcClient(command=[sys.executable, str(Path(__file__).with_name('fake_omp.py'))],
            custom_tools=[tool], max_event_history=8,
            env={'OMP_FAKE_SESSION_DIR': directory, **env})

    def test_stream_does_not_collect_or_fail_when_sdk_ring_rolls_over(self):
        from omp_rpc import RpcError
        from bridge.omp_client import stream_turn
        with tempfile.TemporaryDirectory() as directory:
            # Reproduce the previous collector failure using the real SDK's bounded ring.
            with self.client(directory, OMP_FAKE_BURST='1024') as client, self.assertRaisesRegex(RpcError, 'history limit'):
                client.prompt_and_wait('offline', timeout=5)
            events = []
            with self.client(directory, OMP_FAKE_BURST='1024') as client:
                with patch.object(client, 'prompt_and_wait', side_effect=AssertionError('must not collect a full turn')):
                    answer = stream_turn(client, 'offline', lambda kind, payload: events.append((kind, payload)))
            self.assertEqual(answer, '3')
            self.assertEqual(sum(kind == 'token' for kind, _ in events), 1024)
            self.assertEqual(sum(kind == 'tool_call' for kind, _ in events), 1)
            self.assertEqual(sum(kind == 'tool_result' for kind, _ in events), 1)
            terminal = next(payload for kind, payload in events if kind == 'tool_result')
            self.assertEqual(terminal['text'], '3')
            self.assertNotIn('provenance', terminal)
            self.assertNotIn('evidence_id', terminal)

    def test_stream_survives_sdk_default_research_deadline(self):
        import omp_rpc.client as sdk
        from omp_rpc import RpcTimeoutError
        from bridge.omp_client import stream_turn
        original_time = sdk.time
        accelerated = SimpleNamespace(monotonic=lambda: original_time.monotonic() * 1000, sleep=original_time.sleep)
        with tempfile.TemporaryDirectory() as directory:
            with self.client(directory, OMP_FAKE_DELAY='0.1') as client:
                with patch.object(sdk, 'time', accelerated), self.assertRaises(RpcTimeoutError):
                    client.prompt_and_wait('offline')
            with self.client(directory, OMP_FAKE_DELAY='0.1') as client:
                with patch.object(sdk, 'time', accelerated):
                    self.assertEqual(stream_turn(client, 'offline', lambda *args: None), '3')

    def test_stream_surfaces_late_prompt_admission_failure(self):
        from bridge.omp_client import stream_turn
        with tempfile.TemporaryDirectory() as directory:
            with self.client(directory, OMP_FAKE_LATE_ERROR='1') as client, self.assertRaises(RuntimeError):
                stream_turn(client, 'offline', lambda *args: None)

    def test_stream_detects_dead_transport_instead_of_waiting_forever(self):
        from omp_rpc import RpcError
        from bridge.omp_client import stream_turn
        with tempfile.TemporaryDirectory() as directory:
            with self.client(directory, OMP_FAKE_EXIT_ON_PROMPT='1') as client, self.assertRaises(RpcError):
                stream_turn(client, 'offline', lambda *args: None)
