#!/usr/bin/env python3
"""Boot only a disposable GitHub Actions candidate; never operate an installed host."""
import base64
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import platform
import re
import secrets
import shutil
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parent
LABEL = 'kernscale.trading.smoke'
POSTGRES = 'postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac'
REDIS = 'redis:7@sha256:2e3eb11462c4e3759e6972678dd051d150ff54b1e60714b3c33b701260172a90'


def guard(env, system):
    if env.get('GITHUB_ACTIONS') != 'true' or env.get('RUNNER_OS') != 'Linux' or system != 'Linux':
        raise ValueError('This smoke runs only on GitHub Actions Linux')
    if env.get('GITHUB_REPOSITORY') != 'kernscale/Kernscale-Trading':
        raise ValueError('Exact private source repository required')
    for key in ['GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT']:
        if not re.fullmatch(r'[1-9][0-9]{0,19}', env.get(key, '')):
            raise ValueError('Exact GitHub run identity required')
    commit, digest = env.get('GITHUB_SHA', ''), env.get('IMAGE_DIGEST', '')
    if not re.fullmatch(r'[a-f0-9]{40}', commit) or not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
        raise ValueError('Exact immutable candidate required')
    return ('kth-ci-' + env['GITHUB_RUN_ID'] + '-' + env['GITHUB_RUN_ATTEMPT'],
            'ghcr.io/kernscale/kernscale-trading@' + digest, commit)


def owned(labels, prefix, project=None):
    return (isinstance(labels, dict) and labels.get(LABEL) == prefix
            and (project is None or labels.get('com.docker.compose.project') == project))


def overrides(prefix):
    def project(role, services, volumes, networks):
        return {'name': prefix + '-' + role,
                'services': {name: {'labels': {LABEL: prefix}} for name in services},
                'volumes': {name: {'name': prefix + '-' + name, 'external': True} for name in volumes},
                'networks': {name: {'name': prefix + '-' + name, 'external': True} for name in networks}}
    app = project('app', ['app', 'queue', 'scheduler'], ['storage'], ['data', 'egress', 'ingress'])
    app['services']['app']['ports'] = [{'target': 8000, 'published': '0', 'host_ip': '127.0.0.1', 'protocol': 'tcp'}]
    app['services']['app']['labels'].update({'traefik.enable': 'false', 'kernscale.proxy': 'false'})
    return app, project('state', ['postgres', 'redis'], ['postgres', 'redis'], ['data'])


class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.paths = set()

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        url = attrs.get('src') if tag == 'script' else attrs.get('href') if tag == 'link' else None
        if url and urlsplit(url).path.startswith('/build/') and urlsplit(url).path.endswith(('.js', '.css')):
            self.paths.add(urlsplit(url).path)


class Smoke:
    def __init__(self, prefix, image, commit, directory):
        self.prefix, self.image, self.commit, self.directory = prefix, image, commit, directory
        self.environment = dict(os.environ)
        self.resources = []
        self.started_projects = []
        self.receipt = {'image': image, 'sourceCommit': commit, 'ciRuntimeAccepted': False,
                        'productionRuntimeAccepted': False, 'checks': {}, 'cleanupVerified': False}

    def run(self, command, *, check=True, timeout=120):
        result = subprocess.run(command, env=self.environment, capture_output=True, text=True, timeout=timeout)
        if check and result.returncode:
            raise RuntimeError('CI smoke command failed: ' + ' '.join(command)[:350] + ' exit ' + str(result.returncode))
        return result

    def compose(self, role, *args, check=True):
        filename = 'compose.yaml' if role == 'app' else 'state.compose.yaml'
        return self.run(['docker', 'compose', '--project-name', self.prefix + '-' + role, '-f', str(ROOT / filename), '-f',
                         str(self.directory / (role + '.json')), '--profile', 'workers', *args], check=check)

    def prepare(self):
        password, redis_password = secrets.token_hex(32), secrets.token_hex(32)
        app_key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
        for value in [password, redis_password, app_key]:
            print('::add-mask::' + value, flush=True)
        runtime = '\n'.join(['APP_NAME=TradingSmoke', 'APP_KEY=' + app_key,
            'APP_URL=https://trading.kernscale.invalid', 'SESSION_SECURE_COOKIE=true',
            'DB_CONNECTION=pgsql', 'DB_HOST=postgres', 'DB_DATABASE=trading', 'DB_USERNAME=trading',
            'DB_PASSWORD=' + password, 'QUEUE_CONNECTION=redis', 'REDIS_CLIENT=phpredis',
            'REDIS_HOST=redis', 'REDIS_PASSWORD=' + redis_password, 'CACHE_STORE=database',
            'SESSION_DRIVER=database', 'MAIL_MAILER=smtp', 'MAIL_HOST=mail-fixture', 'MAIL_PORT=2525',
            'MAIL_FROM_ADDRESS=ci@kernscale.invalid', 'LOG_CHANNEL=stderr', 'TRADING_BRIDGE_URL=',
            'TRADING_BRIDGE_TOKEN=']) + '\n'
        for name, content in [('runtime.env', runtime), ('postgres.env',
                'POSTGRES_DB=trading\nPOSTGRES_USER=trading\nPOSTGRES_PASSWORD=' + password + '\n'),
                ('redis.conf', 'bind 0.0.0.0\nprotected-mode yes\nappendonly yes\nrequirepass ' + redis_password + '\n')]:
            path = self.directory / name
            path.write_text(content)
            path.chmod(0o600)
        # Redis must read this bind mount; its host parent remains private.
        (self.directory / 'redis.conf').chmod(0o644)
        self.environment.update({'SLOT': 'blue', 'APP_IMAGE': self.image, 'COMMIT_SHA': self.commit,
            'ROUTE_PRIORITY': '1', 'RUNTIME_ENV_FILE': str(self.directory / 'runtime.env'),
            'POSTGRES_ENV_FILE': str(self.directory / 'postgres.env'), 'REDIS_CONFIG_FILE': str(self.directory / 'redis.conf'),
            'POSTGRES_IMAGE': POSTGRES, 'REDIS_IMAGE': REDIS})
        for role, override in zip(['app', 'state'], overrides(self.prefix)):
            (self.directory / (role + '.json')).write_text(json.dumps(override))
            existing = self.run(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + override['name']])
            if existing.stdout.strip():
                raise RuntimeError('Refusing existing CI project')
        for kind, names in [('network', ['data', 'egress', 'ingress']), ('volume', ['storage', 'postgres', 'redis'])]:
            for suffix in names:
                name = self.prefix + '-' + suffix
                if self.run(['docker', kind, 'inspect', name], check=False).returncode == 0:
                    raise RuntimeError('Refusing existing CI resource')
                command = ['docker', kind, 'create', '--label', LABEL + '=' + self.prefix]
                if kind == 'network' and suffix == 'data':
                    command.append('--internal')
                self.run(command + [name])
                self.resources.append((kind, name))

    def request(self, path):
        try:
            with urlopen(self.url + path, timeout=5) as response:
                return response.status, response.read(4_000_000).decode()
        except HTTPError as error:
            return error.code, error.read(4_000_000).decode()

    def wait_ready(self, status):
        for _ in range(60):
            try:
                code, body = self.request('/ready')
                if code == status:
                    return json.loads(body)
            except (URLError, OSError, ValueError):
                pass
            time.sleep(1)
        raise RuntimeError('Expected readiness status was not observed: ' + str(status))

    def pdf_attachment(self):
        encoded = base64.b64encode((ROOT.parent / 'tests/Fixtures/research-evidence.pdf').read_bytes()).decode()
        code = r'''
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (ini_get('upload_max_filesize') !== '10M' || ini_get('post_max_size') !== '12M') exit(1);
$bytes = base64_decode('%s', true);
$path = tempnam(sys_get_temp_dir(), 'ci-evidence-');
try {
    file_put_contents($path, $bytes);
    Spatie\Permission\Models\Role::findOrCreate('researcher', 'web');
    $user = App\Models\User::forceCreate(['name' => 'CI evidence', 'email' => 'evidence-ci@kernscale.invalid',
        'password' => bin2hex(random_bytes(32)), 'membership_status' => 'approved']);
    $user->assignRole('researcher');
    $session = App\Models\ResearchSession::create(['user_id' => $user->id, 'title' => 'CI evidence']);
    $file = new Illuminate\Http\UploadedFile($path, 'evidence.pdf', 'application/pdf', null, true);
    $service = app(App\Services\ResearchAttachments::class);
    $saved = $service->upload($user, $session, $file);
    $snapshot = $service->snapshot($user, $session, [$saved->id]);
    if ($saved->sha256 !== hash('sha256', $bytes) || count($snapshot) !== 1
        || !str_contains($snapshot[0]['text'], 'Attachment fixture evidence')
        || Illuminate\Support\Facades\Storage::disk('local')->get($saved->storage_key) !== $bytes) exit(1);
} finally {
    unlink($path);
}
''' % encoded
        self.compose('app', 'exec', '-T', 'app', 'php', '-r', code)
        self.receipt['checks']['pdfAttachmentStorage'] = True

    def boot(self, artifact):
        self.started_projects.append('state')
        self.compose('state', 'up', '-d', '--wait', '--wait-timeout', '90')
        self.started_projects.append('app')
        self.compose('app', 'up', '-d', 'app')
        port = self.compose('app', 'port', 'app', '8000').stdout.strip()
        if not re.fullmatch(r'127\.0\.0\.1:[0-9]+', port):
            raise RuntimeError('App port escaped the loopback boundary')
        self.url = 'http://' + port
        self.wait_ready(503)
        self.receipt['checks']['beforeMigrations'] = 503
        self.compose('app', 'exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force', '--no-interaction')
        ready = self.wait_ready(200)
        if ready.get('release') != {'commit': self.commit, 'image': self.image}:
            raise RuntimeError('Ready release identity differs')
        self.receipt['checks']['migratedReadiness'] = 200
        identifier = self.compose('app', 'ps', '-q', 'app').stdout.strip()
        info = self.run(['docker', 'inspect', '--format',
            '{"readonly":{{json .HostConfig.ReadonlyRootfs}},"user":{{json .Config.User}}}', identifier]).stdout
        if json.loads(info) != {'readonly': True, 'user': '10001:10001'}:
            raise RuntimeError('Actual read-only/nonroot runtime differs')
        if self.compose('app', 'exec', '-T', 'app', 'id', '-u').stdout.strip() != '10001':
            raise RuntimeError('Actual process UID differs')
        self.receipt['checks']['readonlyUid'] = 10001
        code = ('require "vendor/autoload.php"; $app=require "bootstrap/app.php"; '
                '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                '$r=Illuminate\\Support\\Facades\\Redis::connection(); '
                '$r->set("ci-smoke", "ok"); if($r->get("ci-smoke")!=="ok")exit(1); $r->del("ci-smoke");')
        self.compose('app', 'exec', '-T', 'app', 'php', '-r', code)
        self.receipt['checks']['laravelRedisRoundtrip'] = True
        self.pdf_attachment()
        self.compose('app', 'up', '-d', '--wait', '--wait-timeout', '60', 'queue')
        self.compose('app', 'exec', '-T', 'queue', 'php', 'artisan', 'horizon:status')
        self.receipt['checks']['horizon'] = True
        self.compose('app', 'exec', '-T', 'app', 'php', 'artisan', 'schedule:list', '--no-interaction')
        self.receipt['checks']['schedulerLoadedWithoutStarting'] = True
        status, html = self.request('/login')
        if status != 200 or '@vite/client' in html or ':5173' in html:
            raise RuntimeError('Production login/assets are unavailable')
        assets = Assets()
        assets.feed(html)
        if not any(path.endswith('.js') for path in assets.paths) or not any(path.endswith('.css') for path in assets.paths):
            raise RuntimeError('Production JavaScript and CSS were not rendered')
        for path in assets.paths:
            status, body = self.request(path)
            if status != 200 or not body:
                raise RuntimeError('Production asset unavailable')
        self.receipt['checks']['loginAndAssets'] = {'status': 200, 'assets': len(assets.paths)}
        chrome = shutil.which('google-chrome') or shutil.which('chromium')
        if chrome:
            result = self.run([chrome, '--headless=new', '--disable-gpu', '--no-first-run',
                '--user-data-dir=' + str(self.directory / 'chrome'), '--window-size=1280,900',
                '--virtual-time-budget=5000', '--screenshot=' + str(artifact / 'login.png'), '--dump-dom', self.url + '/login'], timeout=40)
            if not all(value in result.stdout for value in ['type="email"', 'type="password"', '<button', 'Sign in with a passkey']):
                raise RuntimeError('React login was not rendered by preinstalled Chrome')
            self.receipt['checks']['chromeLogin'] = True
        else:
            self.receipt['checks']['chromeLogin'] = 'unavailable-preinstalled-browser'
        self.receipt['ciRuntimeAccepted'] = True

    def cleanup(self):
        for role in reversed(self.started_projects):
            project = self.prefix + '-' + role
            identifiers = self.run(['docker', 'ps', '-aq', '--filter',
                'label=com.docker.compose.project=' + project]).stdout.split()
            for identifier in identifiers:
                labels = json.loads(self.run(['docker', 'inspect', '--format', '{{json .Config.Labels}}', identifier]).stdout)
                if not owned(labels, self.prefix, project):
                    raise RuntimeError('Container cleanup ownership differs')
                self.run(['docker', 'rm', '-f', identifier])
        for kind, name in reversed(self.resources):
            labels = json.loads(self.run(['docker', kind, 'inspect', '--format', '{{json .Labels}}', name]).stdout)
            if not owned(labels, self.prefix):
                raise RuntimeError('Resource cleanup ownership differs')
            self.run(['docker', kind, 'rm', name])
        self.receipt['cleanupVerified'] = True


def main():
    prefix, image, commit = guard(os.environ, platform.system())
    runner_temp = Path(os.environ['RUNNER_TEMP']).resolve()
    os.umask(0o077)
    artifact = runner_temp / (prefix + '-receipt')
    artifact.mkdir(mode=0o700)
    with tempfile.TemporaryDirectory(prefix=prefix + '-', dir=runner_temp) as raw:
        directory = Path(raw)
        directory.chmod(0o700)
        smoke = Smoke(prefix, image, commit, directory)
        failure = None
        try:
            smoke.prepare()
            smoke.boot(artifact)
        except Exception as error:
            failure = type(error).__name__ + ': ' + str(error)[:500]
        finally:
            try:
                smoke.cleanup()
            except Exception as error:
                smoke.receipt['cleanupFailure'] = type(error).__name__ + ': ' + str(error)[:500]
                failure = failure or 'CleanupFailed'
            if failure:
                smoke.receipt['ciRuntimeAccepted'] = False
                smoke.receipt['failure'] = failure
            (artifact / 'receipt.json').write_text(json.dumps(smoke.receipt, indent=2) + '\n')
    print('CI smoke receipt: ' + str(artifact / 'receipt.json'))
    if failure:
        raise SystemExit('CI image smoke failed; see sanitized receipt')


if __name__ == '__main__':
    main()
