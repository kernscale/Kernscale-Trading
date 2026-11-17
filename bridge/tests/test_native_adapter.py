"""Use the actual pinned SDK/R1 package, with a synthetic OMP transport only."""
import importlib.util
import io
import asyncio
import json
from contextlib import redirect_stdout
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


@unittest.skipUnless(importlib.util.find_spec('omp_rpc') and importlib.util.find_spec('trading_r1'),
                     'requires operator-pinned OMP SDK and R1 packages')
class NativeAdapterTest(unittest.TestCase):
    def test_worker_uses_native_sdk_and_restricted_tools(self):
        from omp_rpc import RpcClient
        from bridge import worker
        from bridge.r1_tools import build_tools as native_build_tools
        fixture = Path(__file__).with_name('fake_omp.py')
        admitted = {}
        readers = []
        configurations = []

        def tools(*args, **kwargs):
            readers.append(args[0])
            return native_build_tools(*args, **kwargs)

        def client(**kwargs):
            admitted.update(kwargs)
            config_dir = kwargs.get('env', {}).get('PI_CODING_AGENT_DIR', str(Path.home() / '.omp/agent'))
            configurations.append(json.loads((Path(config_dir) / 'models.json').read_text()))
            allocated = 'native-session.jsonl' if len(configurations) == 1 else 'unexpected-new-session.jsonl'
            return RpcClient(command=[sys.executable, str(fixture)], custom_tools=kwargs['custom_tools'], max_event_history=kwargs.get('max_event_history', 32), env={'OMP_FAKE_SESSION_DIR': str(kwargs['session_dir']), 'OMP_FAKE_ALLOCATED_FILE': allocated})

        request = {'session_id': '00000000-0000-0000-0000-000000000001',
                   'prompt': '/arbitrary-command', 'model': 'deepseek-v4.1-flash'}
        with tempfile.TemporaryDirectory() as directory:
            environment = {'HOME': directory, 'TR1_HOME': directory + '/r1',
                'TRADING_OMP_REVISION': worker.OMP_REVISION, 'TRADING_OMP_EXECUTABLE': 'fixture',
                'TRADING_GO_BASE_URL': 'https://opencode.ai/inference/go/openai/v1', 'OPENCODE_API_KEY': 'offline',
                'TAURIC_API_KEY': ''}
            output = io.StringIO()
            with patch.dict(os.environ, environment), patch('bridge.worker.os.geteuid', return_value=21001), patch('bridge.worker.signal.signal'), patch('omp_rpc.RpcClient', side_effect=client), patch('bridge.r1_tools.build_tools', side_effect=tools), patch('sys.stdin', io.StringIO(json.dumps(request))), redirect_stdout(output):
                worker.main()
                # State left by SIGKILL after first transcript materialization, before final pointer update.
                session_root = Path(directory) / 'sessions' / request['session_id']
                (session_root / '.bridge-session').unlink()
                binding_path = session_root / '.bridge-binding.json'
                binding = json.loads(binding_path.read_text())
                binding_path.write_text(json.dumps({**binding, 'state': 'allocated'}))
                with patch('sys.stdin', io.StringIO(json.dumps(request))):
                    worker.main()
                other_request = {**request, 'session_id': '00000000-0000-0000-0000-000000000002'}
                with patch('sys.stdin', io.StringIO(json.dumps(other_request))):
                    worker.main()
            expected_sessions = [request['session_id'], request['session_id'], other_request['session_id']]
            for reader, config, expected_session in zip(readers, configurations, expected_sessions):
                expected = {'User-Agent': 'kernscale-trading-hub/0.1 (omp-r1)', 'x-opencode-session': expected_session}
                self.assertEqual(reader.default_headers, expected)
                self.assertEqual(config['providers']['kernscale-go']['headers'], expected)
            self.assertEqual(len(readers), 3)
            self.assertEqual(Path(admitted['env']['PI_CODING_AGENT_DIR']), Path(directory) / 'sessions' / other_request['session_id'] / '.omp-agent')
            self.assertFalse((Path(directory) / '.omp/agent/models.json').exists())
            events = [json.loads(line) for line in output.getvalue().splitlines()]
            self.assertEqual(events[-1]['type'], 'completed')
            first_turn = events[:next(index for index, event in enumerate(events) if event['type'] == 'completed')]
            calls = [event['payload'] for event in first_turn if event['type'] == 'tool_call']
            results = [event['payload'] for event in first_turn if event['type'] == 'tool_result']
            self.assertEqual(len(calls), 1)
            self.assertEqual(len(results), 1)
            self.assertEqual(calls[0]['call_id'], results[0]['call_id'])
            self.assertEqual(results[0]['result']['details']['evidence_id'], 'e1')
            self.assertEqual(results[0]['evidence_id'], 'e1')
            self.assertEqual(results[0]['text'], results[0]['result']['details']['text'])
            self.assertEqual(results[0]['provenance'], results[0]['result']['details']['provenance'])
            self.assertEqual(results[0]['call_id'], 'call-1')
            native = [event['payload'] for event in events if event['type'] == 'native_session']
            self.assertEqual([event['resumed'] for event in native], [False, True, False])
            self.assertEqual(admitted['tools'], ())
            self.assertIn('--no-extensions', admitted['extra_args'])
            self.assertTrue(admitted['no_rules'])
            self.assertTrue(admitted['no_skills'])
            config = configurations[-1]
            self.assertEqual(config['providers']['kernscale-go']['baseUrl'], 'https://opencode.ai/inference/go/openai/v1')
            model = config['providers']['kernscale-go']['models'][0]
            self.assertNotIn('contextWindow', model)
            self.assertNotIn('maxTokens', model)
            pointer = Path(directory) / 'sessions' / request['session_id'] / '.bridge-session'
            self.assertEqual(pointer.read_text(), 'native-session.jsonl')

    def test_worker_persists_only_real_transcripts_including_failed_turns(self):
        from omp_rpc import RpcClient
        from bridge import worker
        fixture = Path(__file__).with_name('fake_omp.py')
        request = {'session_id': '00000000-0000-0000-0000-000000000001',
                   'prompt': 'offline', 'model': 'deepseek-v4.1-flash'}
        for persist, error, expected in ((False, False, FileNotFoundError), (True, True, RuntimeError)):
            with self.subTest(persist=persist), tempfile.TemporaryDirectory() as directory:
                environment = {'HOME': directory, 'TR1_HOME': directory + '/r1',
                    'TRADING_OMP_REVISION': worker.OMP_REVISION, 'TRADING_OMP_EXECUTABLE': 'fixture',
                    'TRADING_GO_BASE_URL': 'https://opencode.ai/inference/go/openai/v1', 'TAURIC_API_KEY': ''}

                def client(**kwargs):
                    return RpcClient(command=[sys.executable, str(fixture)], custom_tools=kwargs['custom_tools'],
                        env={'OMP_FAKE_SESSION_DIR': str(kwargs['session_dir']),
                             'OMP_FAKE_NO_PERSIST': '0' if persist else '1',
                             'OMP_FAKE_PROMPT_ERROR': '1' if error else '0'})

                output = io.StringIO()
                with patch.dict(os.environ, environment), patch('bridge.worker.os.geteuid', return_value=21001), patch('bridge.worker.signal.signal'), patch('omp_rpc.RpcClient', side_effect=client), patch('bridge.r1_tools.build_tools', return_value=[]), patch('sys.stdin', io.StringIO(json.dumps(request))), redirect_stdout(output), self.assertRaises(expected):
                    worker.main()
                pointer = Path(directory) / 'sessions' / request['session_id'] / '.bridge-session'
                self.assertEqual(pointer.exists(), persist)
                events = [json.loads(line) for line in output.getvalue().splitlines()]
                self.assertFalse(any(event['type'] == 'completed' for event in events))

    def test_native_sdk_switch_session_rejects_directory_and_escape(self):
        from omp_rpc import RpcClient, RpcCommandError
        from bridge.worker import owned_session_file
        fixture = Path(__file__).with_name('fake_omp.py')
        with tempfile.TemporaryDirectory() as directory:
            with RpcClient(command=[sys.executable, str(fixture)]) as client:
                with self.assertRaises(RpcCommandError):
                    client.switch_session(directory)
            with self.assertRaises((RuntimeError, ValueError)):
                owned_session_file(Path(directory), directory)
            with self.assertRaises(ValueError):
                owned_session_file(Path(directory), str(fixture))

    def test_workspace_proposal_uses_actual_sdk_without_mutating_snapshot(self):
        from omp_rpc import RpcClient, host_tool
        from bridge.workspace_tools import build_workspace_tools
        fixture = Path(__file__).with_name('fake_omp.py')
        events = []
        snapshot = {'thesis': {'content': 'Original thesis', 'version': 1}, 'board': []}
        tools = build_workspace_tools(snapshot, lambda kind, payload: events.append((kind, payload)), host_tool)
        with RpcClient(command=[sys.executable, str(fixture)], custom_tools=tools, env={'OMP_FAKE_WORKSPACE': '1'}) as client:
            turn = client.prompt_and_wait('offline proposal', timeout=5)
        self.assertIn('awaiting human review', turn.require_assistant_text())
        self.assertEqual(events, [('workspace_proposal', {'operation': 'update_thesis', 'content': 'Proposed thesis', 'expected_version': 1})])
        self.assertEqual(snapshot['thesis']['content'], 'Original thesis')

    def test_public_fetch_redirect_cannot_reach_private_network(self):
        import httpx
        from bridge.network import public_fetch
        actual_client = httpx.AsyncClient
        observed = []

        def redirect(request):
            observed.append(request)
            return httpx.Response(302, headers={'location': 'http://169.254.169.254/latest/meta-data/'})

        def client(**kwargs):
            self.assertFalse(kwargs['trust_env'])
            self.assertFalse(kwargs['follow_redirects'])
            return actual_client(transport=httpx.MockTransport(redirect), **kwargs)

        with patch('bridge.network.httpx.AsyncClient', side_effect=client):
            result = asyncio.run(public_fetch('https://93.184.216.34/research'))
        self.assertFalse(result.ok)
        self.assertEqual(len(observed), 1)
        self.assertEqual(observed[0].url.host, '93.184.216.34')
        self.assertEqual(observed[0].extensions['sni_hostname'], '93.184.216.34')
        self.assertNotIn('authorization', observed[0].headers)

    def test_native_dispatch_evidence_replay_and_sdk_host_tool(self):
        from omp_rpc import RpcClient, host_tool
        from trading_r1.providers.profiles import ModelProfile
        from bridge.r1_tools import build_tools
        events = []
        emit = lambda kind, payload: events.append((kind, payload))
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'TAURIC_API_KEY': ''}):
            reader = ModelProfile(name='offline-reader', model='offline')
            tools = build_tools(reader, emit, host_tool, directory)
            names = {tool.name for tool in tools}
            self.assertIn('calc', names)
            self.assertNotIn('bash', names)
            self.assertFalse(any(name.startswith('data_') for name in names))
            fixture = Path(__file__).with_name('fake_omp.py')
            with RpcClient(command=[sys.executable, str(fixture)], custom_tools=tools) as client:
                turn = client.prompt_and_wait('offline fixture', timeout=5)
                self.assertIn('3', turn.require_assistant_text())
                self.assertEqual(turn.result.status, 'completed')
            self.assertTrue(any(kind == 'record' for kind, _ in events))
            self.assertTrue((Path(directory) / 'evidence.jsonl').exists())
            resumed = build_tools(reader, emit, host_tool, directory)
            calc = next(tool for tool in resumed if tool.name == 'calc')
            answer = calc.execute({'expression': 'prev:e1.result+4'}, SimpleNamespace(tool_call_id='call-2'))
            self.assertIn('7', answer['content'][0]['text'])
            with self.assertRaises(RuntimeError):
                calc.execute({'expression': '__import__("os")'}, SimpleNamespace(tool_call_id='call-3'))
            self.assertFalse(any(kind == 'tool_result' for kind, payload in events))


if __name__ == '__main__':
    unittest.main()
