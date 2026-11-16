"""Real loopback HTTP contract consumed by the Laravel bridge client; no providers."""
import json
import os
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from uuid import uuid4


class HttpContractTest(unittest.TestCase):
    def test_real_http_run_poll_replay_and_researcher_denial(self):
        token = 'offline-http-contract-' + 'x' * 32
        with tempfile.TemporaryDirectory() as directory, socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
            reservation.close()
            environment = {**os.environ, 'TRADING_BRIDGE_TOKEN': token,
                           'TRADING_BRIDGE_DB': str(Path(directory) / 'runs.sqlite'),
                           'TRADING_BRIDGE_IDENTITIES': str(Path(directory) / 'not-admitted.json')}
            process = subprocess.Popen([sys.executable, '-m', 'uvicorn', 'bridge.app:create_app',
                '--factory', '--host', '127.0.0.1', '--port', str(port)], env=environment,
                cwd=Path(__file__).resolve().parents[2], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                def request(path, method='GET', payload=None):
                    headers = {'Authorization': 'Bearer ' + token, 'X-Workspace-ID': 'kernscale-trading'}
                    data = None if payload is None else json.dumps(payload).encode()
                    if data is not None:
                        headers['Content-Type'] = 'application/json'
                    with urlopen(Request(f'http://127.0.0.1:{port}' + path, data=data,
                                         headers=headers, method=method), timeout=2) as response:
                        return json.load(response)
                for _ in range(100):
                    try:
                        health = request('/health')
                        break
                    except URLError:
                        if process.poll() is not None:
                            self.fail('offline bridge exited before readiness')
                        time.sleep(.05)
                else:
                    self.fail('offline bridge did not become ready')
                self.assertFalse(health['research']['available'])
                run = str(uuid4())
                path = '/v1/researchers/1/runs/' + run
                body = {'workspace_id': 'kernscale-trading', 'tenant_id': 'kernscale-trading',
                        'session_id': str(uuid4()), 'prompt': 'offline unavailable runtime', 'model': 'deepseek-v4.1-flash'}
                self.assertEqual(request(path, 'PUT', body)['id'], run)
                for _ in range(100):
                    snapshot = request(path + '/events?after=0')
                    if snapshot['status'] == 'failed':
                        break
                    time.sleep(.01)
                self.assertEqual(snapshot['status'], 'failed')
                self.assertEqual(request(path, 'PUT', body)['status'], 'failed')
                cursor = snapshot['events'][-1]['id']
                self.assertEqual(request(path + '/events?after=' + str(cursor))['events'], [])
                with self.assertRaises(HTTPError) as denied:
                    request(path.replace('/researchers/1/', '/researchers/2/'))
                self.assertEqual(denied.exception.code, 404)
                with self.assertRaises(HTTPError) as conflict:
                    request(path, 'PUT', {**body, 'prompt': 'different'})
                self.assertEqual(conflict.exception.code, 409)
            finally:
                process.terminate()
                process.wait(timeout=10)
