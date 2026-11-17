"""Real pinned SDK and OS pipes, synthetic OMP process, no provider requests."""
import asyncio
import importlib.util
import json
import os
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from uuid import uuid4

from bridge.clarification_channel import ControlPump, WorkerClarifications
from bridge.store import Store


def wait_for(predicate):
    deadline = time.monotonic() + 4
    while time.monotonic() < deadline:
        if predicate():
            return True
        time.sleep(0.02)
    return False


class ClarificationTransportTests(unittest.TestCase):
    def test_full_pipe_never_blocks_shutdown_or_the_coordinator(self):
        read_fd, write_fd = os.pipe()
        writer = os.fdopen(write_fd, 'w')
        os.set_blocking(write_fd, False)
        try:
            while True:
                os.write(write_fd, b'x' * 4096)
        except BlockingIOError:
            pass
        store = SimpleNamespace(clarification_controls=lambda *args: [{'type': 'clarification_closed', 'request_id': str(uuid4()), 'status': 'expired'}])
        errors = []
        pump = ControlPump(store, '1', str(uuid4()), SimpleNamespace(stdin=writer, poll=lambda: None), lambda: errors.append('unexpected'))
        try:
            pump.close()
            self.assertFalse(pump.thread.is_alive())
            self.assertEqual(errors, [])
        finally:
            writer.close()
            os.close(read_fd)

    def test_unknown_or_untyped_control_frames_cannot_execute_actions(self):
        helper = WorkerClarifications(lambda *args: self.fail('no pending tool should emit'))
        for frame in ({'type': 'rpc', 'command': 'shell'}, {'type': 'clarification_closed', 'request_id': str(uuid4()), 'status': 'expired', 'path': '/private'}):
            with self.assertRaises(ValueError):
                helper.receive(frame)
        helper.receive({'type': 'clarification_closed', 'request_id': str(uuid4()), 'status': 'expired'})
        self.assertEqual(helper.pending, {})


@unittest.skipUnless(importlib.util.find_spec('omp_rpc'), 'requires pinned public SDK')
class ClarificationChannelTests(unittest.TestCase):
    def exercise(self, mode, *, native=False):
        from omp_rpc import RpcClient, host_tool
        from bridge.omp_client import stream_turn
        with tempfile.TemporaryDirectory() as directory:
            store = Store(Path(directory) / 'runs.sqlite')
            run_id = str(uuid4())
            store.create('1', run_id, {'session_id': str(uuid4()), 'prompt': 'offline'})
            store.transition('1', run_id, 'running')
            observations, outcomes, errors = [], [], []

            def emit(kind, payload):
                observations.append((kind, payload))
                if kind == 'clarification_requested':
                    store.open_clarification('1', run_id, payload)
                elif kind == 'clarification_consumed':
                    store.consume_clarification('1', run_id, payload)
                else:
                    store.event(run_id, kind, payload)

            read_fd, write_fd = os.pipe()
            reader, writer = os.fdopen(read_fd), os.fdopen(write_fd, 'w')
            helper = WorkerClarifications(emit, reader)
            if native:
                from trading_r1.providers.profiles import ModelProfile
                from bridge.r1_tools import build_tools
                tools = build_tools(ModelProfile(name='offline-reader', model='offline'), emit, host_tool, directory, clarifications=helper)
                description = next(tool.description for tool in tools if tool.name == 'ask_user')
                self.assertIn('300 seconds', description)
                self.assertIn('expiry returns no answer', description)
                self.assertIn('not execution authority', description)
                self.assertNotIn('recommended', description)
                self.assertNotIn('first option', description)
            else:
                def execute(args, context):
                    helper.local.context = context
                    answers = asyncio.run(helper.ask([{'id': str(i), **question} for i, question in enumerate(args['questions'])]))
                    return json.dumps(answers)
                tools = [host_tool(name='ask_user', description='Offline clarification fixture', parameters={'type': 'object'}, execute=execute)]
            pump = None
            thread = None
            try:
                with RpcClient(command=[sys.executable, str(Path(__file__).with_name('fake_omp.py'))], custom_tools=tools,
                               env={'OMP_FAKE_CLARIFICATION': '1', 'OMP_FAKE_SESSION_DIR': directory}, max_event_history=8) as client:
                    def turn():
                        try:
                            outcomes.append(stream_turn(client, 'offline', emit))
                        except Exception as error:
                            errors.append(type(error).__name__)
                    thread = threading.Thread(target=turn, daemon=True)
                    thread.start()
                    self.assertTrue(wait_for(lambda: store.get('1', run_id)['status'] == 'waiting_for_input'))
                    self.assertEqual(outcomes, [])
                    self.assertEqual(client.get_state().session_id, 'offline-session')  # Actual RPC stays live while the host callback waits.
                    request = next(payload for kind, payload in observations if kind == 'clarification_requested')
                    request_id = request['request_id']
                    if mode == 'cancel':
                        store.request_cancel('1', run_id)
                        client.abort()
                    else:
                        if mode == 'expire':
                            with store.connect() as db:
                                db.execute("UPDATE clarifications SET expires_at='2000-01-01T00:00:00+00:00'")
                        else:
                            body = {'answers': [{'id': '0', 'answer': 'Second'}]}
                            receipt = store.answer_clarification('1', run_id, request_id, body)
                            self.assertEqual(receipt['clarification']['status'], 'accepted')
                            self.assertIsNone(receipt['clarification']['consumed_at'])
                            control = Store(store.path).clarification_controls('1', run_id)[0]
                        pump = ControlPump(store, '1', run_id, SimpleNamespace(stdin=writer, poll=lambda: None), lambda: errors.append('control_failed'))
                    thread.join(5)
                    self.assertFalse(thread.is_alive())
                    self.assertTrue(wait_for(lambda: not helper.pending))
                    if mode == 'answer':
                        self.assertFalse(errors)
                        self.assertIn('Second', outcomes[0])
                        helper.receive(control)  # Duplicate delivery after the receipt cannot produce a second tool result.
                        replay = store.answer_clarification('1', run_id, request_id, body)
                        self.assertEqual(replay['clarification']['status'], 'consumed')
                        self.assertEqual(sum(kind == 'clarification_consumed' for kind, _ in observations), 1)
                        self.assertEqual(sum(kind == 'tool_result' for kind, _ in observations), 1)
                    elif mode == 'expire':
                        self.assertFalse(errors)
                        self.assertNotIn('First', outcomes[0])
                        self.assertNotIn('Second', outcomes[0])
                        self.assertFalse(any(kind == 'clarification_consumed' for kind, _ in observations))
                        self.assertEqual(store.clarification_controls('1', run_id)[0]['status'], 'expired')
                    else:
                        self.assertEqual(outcomes, [])
                        self.assertTrue(errors)
                        self.assertFalse(any(kind in ('clarification_consumed', 'tool_result') for kind, _ in observations))
            finally:
                helper.close()
                if pump:
                    pump.close()
                writer.close()
                self.assertTrue(wait_for(helper.closed.is_set))
                reader.close()

    def test_actual_sdk_pauses_until_committed_answer_and_consumes_once(self):
        self.exercise('answer')

    def test_actual_sdk_expiry_has_no_recommended_option_answer(self):
        self.exercise('expire')

    def test_actual_sdk_abort_remains_live_while_waiting(self):
        self.exercise('cancel')

    @unittest.skipUnless(importlib.util.find_spec('trading_r1'), 'requires admitted private R1 pin')
    def test_actual_r1_ask_user_spec_dispatch_and_result(self):
        self.exercise('answer', native=True)
