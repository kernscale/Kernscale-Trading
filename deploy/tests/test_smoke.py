import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('smoke', Path(__file__).parents[1] / 'smoke.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class SmokeBoundaryTest(unittest.TestCase):
    def environment(self):
        return {'GITHUB_ACTIONS': 'true', 'RUNNER_OS': 'Linux',
                'GITHUB_REPOSITORY': 'kernscale/Kernscale-Trading',
                'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '2',
                'GITHUB_SHA': 'a' * 40, 'IMAGE_DIGEST': 'sha256:' + 'b' * 64}

    def test_exact_ci_guard(self):
        prefix, image, commit = m.guard(self.environment(), 'Linux')
        self.assertEqual(prefix, 'kth-ci-123-2')
        self.assertEqual(image, 'ghcr.io/kernscale/kernscale-trading@sha256:' + 'b' * 64)
        self.assertEqual(commit, 'a' * 40)

    def test_guard_rejects_local_wrong_repo_and_malformed_identity(self):
        for key, value in [('GITHUB_ACTIONS', 'false'), ('RUNNER_OS', 'macOS'),
                           ('GITHUB_REPOSITORY', 'another/repo'), ('GITHUB_RUN_ID', '../1'),
                           ('GITHUB_RUN_ATTEMPT', '0'), ('GITHUB_SHA', 'main'),
                           ('IMAGE_DIGEST', 'latest')]:
            environment = self.environment()
            environment[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                m.guard(environment, 'Linux')
        with self.assertRaises(ValueError):
            m.guard(self.environment(), 'Darwin')

    def test_overrides_reuse_production_and_isolate_every_resource(self):
        app, state = m.overrides('kth-ci-123-2')
        self.assertEqual(app['services']['app']['ports'][0]['host_ip'], '127.0.0.1')
        self.assertEqual(app['services']['app']['ports'][0]['published'], '0')
        for project in (app, state):
            for kind in ['volumes', 'networks']:
                for value in project[kind].values():
                    self.assertTrue(value['name'].startswith('kth-ci-123-2-'))
                    self.assertTrue(value['external'])
            for service in project['services'].values():
                self.assertEqual(service['labels'][m.LABEL], 'kth-ci-123-2')
                self.assertNotIn('image', service)
                self.assertNotIn('environment', service)
        self.assertNotIn('read_only', app['services']['app'])

    def test_cleanup_never_scans_projects_this_smoke_did_not_start(self):
        smoke = m.Smoke('kth-ci-123-2', 'image', 'commit', Path('/synthetic'))
        with patch.object(smoke, 'run') as run:
            smoke.cleanup()
        run.assert_not_called()

    def test_preexisting_resources_are_refused_before_creation(self):
        with tempfile.TemporaryDirectory() as raw:
            smoke = m.Smoke('kth-ci-123-2', 'image', 'commit', Path(raw))
            with patch('builtins.print'), patch.object(smoke, 'run') as run:
                run.return_value = subprocess.CompletedProcess([], 0, '', '')
                with self.assertRaisesRegex(RuntimeError, 'Refusing existing CI resource'):
                    smoke.prepare()
            self.assertFalse(any('create' in call.args[0] for call in run.call_args_list))
            self.assertEqual(smoke.resources, [])

    def test_actual_compose_merge_retains_runtime_boundaries(self):
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            runtime = root / 'runtime.env'
            runtime.write_text('APP_NAME=Synthetic\n')
            redis = root / 'redis.conf'
            redis.write_text('appendonly yes\n')
            environment = {'PATH': os.environ['PATH'], 'HOME': os.environ['HOME'], 'SLOT': 'blue',
                'APP_IMAGE': 'ghcr.io/kernscale/kernscale-trading@sha256:' + 'a' * 64,
                'COMMIT_SHA': 'b' * 40, 'ROUTE_PRIORITY': '1', 'RUNTIME_ENV_FILE': str(runtime),
                'POSTGRES_ENV_FILE': str(runtime), 'REDIS_CONFIG_FILE': str(redis),
                'POSTGRES_IMAGE': m.POSTGRES, 'REDIS_IMAGE': m.REDIS}
            for role, source, override in zip(['app', 'state'], ['compose.yaml', 'state.compose.yaml'], m.overrides('kth-ci-123-2')):
                target = root / (role + '.json')
                target.write_text(json.dumps(override))
                result = subprocess.run(['docker', 'compose', '--project-name', 'kth-ci-123-2-' + role,
                    '-f', str(m.ROOT / source), '-f', str(target), '--profile', 'workers', 'config', '--format', 'json'],
                    env=environment, check=True, capture_output=True, text=True)
                resolved = json.loads(result.stdout)
                self.assertEqual(resolved['name'], 'kth-ci-123-2-' + role)
                for kind in ['volumes', 'networks']:
                    for value in resolved[kind].values():
                        self.assertTrue(value['name'].startswith('kth-ci-123-2-'))
                if role == 'app':
                    for name in ['app', 'queue', 'scheduler']:
                        service = resolved['services'][name]
                        self.assertTrue(service['read_only'])
                        self.assertEqual(service['user'], '10001:10001')
                        self.assertEqual(service['image'], environment['APP_IMAGE'])
                    self.assertEqual(resolved['services']['app']['ports'][0]['host_ip'], '127.0.0.1')
                else:
                    self.assertTrue(all('ports' not in service for service in resolved['services'].values()))

    def test_cleanup_requires_both_owned_label_and_exact_project(self):
        prefix = 'kth-ci-123-2'
        valid = {m.LABEL: prefix, 'com.docker.compose.project': prefix + '-app'}
        self.assertTrue(m.owned(valid, prefix, prefix + '-app'))
        self.assertFalse(m.owned({}, prefix, prefix + '-app'))
        self.assertFalse(m.owned(valid, 'another-run', prefix + '-app'))
        self.assertFalse(m.owned(valid, prefix, prefix + '-state'))
        self.assertFalse(m.owned(None, prefix))


if __name__ == '__main__':
    unittest.main()
