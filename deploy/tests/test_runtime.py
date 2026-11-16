import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('validate_runtime', ROOT / 'validate.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class RuntimeTest(unittest.TestCase):
    def configured(self, root, **overrides):
        runtime = root / 'runtime.env'
        runtime.write_text('APP_NAME=synthetic\n')
        redis = root / 'redis.conf'
        redis.write_text('appendonly yes\n')
        environment = {'PATH': os.environ['PATH'], 'HOME': os.environ['HOME'], 'SLOT': 'blue',
                       'APP_IMAGE': 'ghcr.io/kernscale/kernscale-trading@sha256:' + 'a' * 64,
                       'COMMIT_SHA': 'b' * 40, 'ROUTE_PRIORITY': '100', 'RUNTIME_ENV_FILE': str(runtime),
                       'POSTGRES_ENV_FILE': str(runtime), 'REDIS_CONFIG_FILE': str(redis),
                       'POSTGRES_IMAGE': 'postgres:17@sha256:' + 'c' * 64,
                       'REDIS_IMAGE': 'redis:7@sha256:' + 'd' * 64}
        environment.update(overrides)
        return environment

    def test_compose_config_and_static_dockerfile(self):
        with tempfile.TemporaryDirectory() as raw:
            environment = self.configured(Path(raw))
            app, state = m.resolved(environment)
            m.validate(app, state)
            self.assertIn('kernscale-trading-hub-blue', app['name'])
            self.assertIn('traefik.http.routers.kernscale-trading-hub-blue.rule', app['services']['app']['labels'])
        dockerfile = (ROOT / 'Dockerfile').read_text()
        self.assertIn('npm run build', dockerfile)
        self.assertIn('USER kernscale', dockerfile)
        self.assertNotIn('COPY . .', dockerfile)
        self.assertNotIn('migrate', dockerfile)
        self.assertNotIn('key:generate', dockerfile)
        self.assertNotIn('bootstrap/cache', '\n'.join(line for line in dockerfile.splitlines() if line.startswith('COPY ')))
        for line in dockerfile.splitlines():
            if line.startswith('FROM ') and ' AS runtime' not in line and ' AS frontend' not in line:
                self.assertIn('@sha256:', line)

    def test_required_image_and_mutable_image_rejected(self):
        with tempfile.TemporaryDirectory() as raw:
            with self.assertRaises(subprocess.CalledProcessError):
                m.resolved(self.configured(Path(raw), APP_IMAGE=''))
            app, state = m.resolved(self.configured(Path(raw), APP_IMAGE='app:latest'))
            with self.assertRaises(ValueError):
                m.validate(app, state)

    def test_public_port_or_worker_ingress_rejected(self):
        with tempfile.TemporaryDirectory() as raw:
            for mutate in ('port', 'network'):
                app, state = m.resolved(self.configured(Path(raw)))
                if mutate == 'port':
                    state['services']['postgres']['ports'] = [{'target': 5432, 'published': '5432'}]
                else:
                    app['services']['queue']['networks']['ingress'] = None
                with self.subTest(mutate=mutate), self.assertRaises(ValueError):
                    m.validate(app, state)


if __name__ == '__main__':
    unittest.main()
