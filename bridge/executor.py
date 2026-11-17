"""Trusted UID admission; the HTTP caller cannot choose an executable or environment."""
import hashlib
import json
import os
import stat
import subprocess
import sys
import threading
from pathlib import Path

from bridge.network import GO_BASE_URL
from bridge.clarification_channel import ControlPump


class IsolationUnavailable(RuntimeError):
    pass


class IsolatedExecutor:
    def __init__(self, store):
        self.store = store
        self.processes = {}
        self.lock = threading.Lock()
        self.cgroup_root = Path("/sys/fs/cgroup/kernscale-trading")

    def capabilities(self):
        # Credentials and researcher admission details never appear in health output.
        available = False
        try:
            config = Path(os.environ.get('TRADING_BRIDGE_IDENTITIES', '/etc/kernscale-trading/researchers.json'))
            info = config.stat()
            if info.st_uid == 0 and not info.st_mode & 0o077:
                identities = json.loads(config.read_text())
                available = bool(identities)
                for researcher in identities:
                    self.admission(researcher)
        except Exception:
            available = False
        return {'available': available,
                'reason': 'admitted' if available else 'OS isolation and runtime qualification required',
                'model': 'deepseek-v4.1-flash', 'data': 'optional; unavailable without backend credentials'}

    def reconcile(self):
        # Fence every old worker and descendant before accepting a replacement run.
        if self.cgroup_root.exists():
            for group in self.cgroup_root.glob("run-*"):
                (group / "cgroup.kill").write_text("1")
                if (group / "cgroup.procs").read_text().strip():
                    raise IsolationUnavailable("old research worker has not exited")

    def admission(self, researcher):
        config = Path(os.environ.get('TRADING_BRIDGE_IDENTITIES', '/etc/kernscale-trading/researchers.json'))
        if sys.platform != 'linux' or os.geteuid() != 0:
            raise IsolationUnavailable('OS UID isolation is not admitted on this host')
        if not (self.cgroup_root / 'cgroup.procs').is_file():
            raise IsolationUnavailable('dedicated cgroup v2 subtree is not admitted')
        info = config.stat()
        if info.st_uid != 0 or info.st_mode & 0o077:
            raise IsolationUnavailable('researcher admission credentials must be root-owned with mode 0600')
        identities = json.loads(config.read_text())
        entry = identities[researcher]
        if entry.get('runtime_qualified') is not True:
            raise IsolationUnavailable('pinned SDK/CLI and host isolation require offline qualification')
        uid, gid = entry['uid'], entry['gid']
        if type(uid) is not int or type(gid) is not int or uid < 10000 or gid < 10000:
            raise IsolationUnavailable('researcher UID/GID must be dedicated unprivileged identities')
        if len({v['uid'] for v in identities.values()}) != len(identities):
            raise IsolationUnavailable('each researcher requires a distinct UID')
        source = Path(__file__).resolve().parent
        for path in (source, *source.parents, *source.rglob('*.py')):
            info = path.stat()
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise IsolationUnavailable('privileged worker source must be root-owned and not writable by others')
        binary = Path(entry['omp']).resolve(strict=True)
        for path in (binary, *binary.parents):
            info = path.stat()
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise IsolationUnavailable('OMP binary and ancestors must be root-protected')
        expected = entry.get('omp_sha256')
        if not expected or hashlib.sha256(binary.read_bytes()).hexdigest() != expected:
            raise IsolationUnavailable('OMP binary digest differs from admitted artifact')
        home = Path(entry['home']).resolve(strict=True)
        info = home.stat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o077:
            raise IsolationUnavailable('researcher home must be UID-owned with mode 0700')
        # Values come only from the protected operator file, never from an API request.
        env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'LANG': 'C.UTF-8',
               'HOME': str(home), 'TR1_HOME': str(home / '.trading-r1'),
               'PYTHONPATH': str(Path(__file__).resolve().parent.parent),
               'TRADING_OMP_EXECUTABLE': entry['omp'],
               'TRADING_OMP_REVISION': entry['omp_revision'],
               'TRADING_GO_BASE_URL': GO_BASE_URL,
               'OPENCODE_API_KEY': entry['go_key']}
        if entry.get('data_key'):
            env['TAURIC_API_KEY'] = entry['data_key']
        return uid, gid, home, env

    def run(self, researcher, run_id, body):
        process = None
        group = None
        control = None
        try:
            uid, gid, home, env = self.admission(researcher)
            with self.lock:
                with self.store.connect() as db:
                    prior = db.execute('SELECT id FROM runs WHERE session_id=? AND id!=?', (body['session_id'], run_id)).fetchall()
                for row in prior:
                    old_group = self.cgroup_root / ('run-' + row['id'])
                    if old_group.exists() and (old_group / 'cgroup.procs').read_text().strip():
                        raise IsolationUnavailable('a previous session worker has not exited')
                if self.store.get(researcher, run_id)['status'] == 'cancel_requested':
                    self.store.transition(researcher, run_id, 'cancelled')
                    return
                group = self.cgroup_root / ('run-' + run_id)
                group.mkdir(mode=0o700)
                process = subprocess.Popen([sys.executable, '-m', 'bridge.worker'],
                    stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                    text=True, cwd=home, env=env, user=uid, group=gid, extra_groups=[],
                    start_new_session=True, umask=0o077)
                (group / "cgroup.procs").write_text(str(process.pid))
                self.processes[run_id] = process
                self.store.transition(researcher, run_id, 'running')
            process.stdin.write(json.dumps(body) + '\n')
            process.stdin.flush()
            control = ControlPump(self.store, researcher, run_id, process, lambda: self.cancel(run_id))
            finished = False
            for line in process.stdout:
                event = json.loads(line)
                kind = event['type']
                if kind == 'clarification_requested':
                    self.store.open_clarification(researcher, run_id, event['payload'])
                    continue
                if kind == 'clarification_consumed':
                    self.store.consume_clarification(researcher, run_id, event['payload'])
                    continue
                if kind == 'completed':
                    finished = True
                self.store.event(run_id, kind, event['payload'])
            code = process.wait()
            status = self.store.get(researcher, run_id)['status']
            clean = self.fence(group)
            if status == 'cancel_requested':
                # Cgroup fencing covers OMP's separate process group and host callbacks.
                self.store.transition(researcher, run_id, 'cancelled' if clean else 'interrupted')
            else:
                self.store.transition(researcher, run_id, 'completed' if code == 0 and finished and clean else 'failed')
        except Exception:
            self.store.event(run_id, 'error', {'message': 'Research runtime unavailable; operator admission or dependencies require attention'})
            self.store.transition(researcher, run_id, 'failed')
        finally:
            if control is not None:
                control.close()
            if process and process.stdin:
                process.stdin.close()
            with self.lock:
                self.processes.pop(run_id, None)
            if group is not None:
                self.fence(group)
            if process and process.poll() is None:
                process.terminate()
                process.wait()

    @staticmethod
    def fence(group):
        (group / "cgroup.kill").write_text("1")
        return not (group / "cgroup.procs").read_text().strip()

    def cancel(self, run_id):
        with self.lock:
            process = self.processes.get(run_id)
            if process and process.poll() is None:
                group = self.cgroup_root / ("run-" + run_id)
                self.fence(group)
