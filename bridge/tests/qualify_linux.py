"""Manual ephemeral GitHub runner qualification, not production/OMP admission."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import grp
import re
import shutil
import subprocess
import sys
import threading
import time
from unittest.mock import patch
from uuid import uuid4


def require(condition):
    if not condition:
        raise RuntimeError('qualification requirement failed')


def guarded_paths(env, expected_sha, *, platform, uid):
    require(platform == 'linux' and uid == 0)
    required = {'GITHUB_ACTIONS': 'true', 'GITHUB_EVENT_NAME': 'workflow_dispatch',
                'GITHUB_REPOSITORY': 'kernscale/Kernscale-Trading', 'GITHUB_REF': 'refs/heads/main',
                'RUNNER_ENVIRONMENT': 'github-hosted', 'RUNNER_OS': 'Linux'}
    require(all(env.get(key) == value for key, value in required.items()))
    require(re.fullmatch('[0-9a-f]{40}', expected_sha or '') is not None and env.get('GITHUB_SHA') == expected_sha)
    require(all(re.fullmatch('[1-9][0-9]{0,19}', env.get(key, '')) for key in ('GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT')))
    name = 'kth-ci-' + env['GITHUB_RUN_ID'] + '-' + env['GITHUB_RUN_ATTEMPT']
    return Path('/var/lib') / name, Path('/sys/fs/cgroup') / name


def protected(path):
    require(path.absolute() == path.resolve(strict=True))
    for item in (path, *path.parents):
        info = item.stat()
        require(info.st_uid == 0 and not info.st_mode & 0o022)


def absent(path):
    require(not path.exists() and not path.is_symlink())


def owned_created(path, inode):
    require(not path.is_symlink() and path.stat().st_uid == 0 and path.stat().st_ino == inode)


def unused_identities():
    used_uids, used_gids = {row.pw_uid for row in pwd.getpwall()}, {row.gr_gid for row in grp.getgrall()}
    for status in Path('/proc').glob('[0-9]*/status'):
        try:
            for line in status.read_text().splitlines():
                if line.startswith('Uid:'):
                    used_uids.update(map(int, line.split()[1:]))
                if line.startswith(('Gid:', 'Groups:')):
                    used_gids.update(map(int, line.split()[1:]))
        except FileNotFoundError:
            pass
    result = [value for value in range(61000, 62000) if value not in used_uids and value not in used_gids]
    require(len(result) >= 2)
    return result[:2]


def wait_for(predicate, seconds=8):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if predicate():
            return True
        time.sleep(0.05)
    return False


def dead(pid):
    try:
        return Path(f'/proc/{pid}/stat').read_text().rsplit(')', 1)[1].split()[0] == 'Z'
    except FileNotFoundError:
        return True


def qualify(expected_sha):
    receipt = {'sourceCommit': expected_sha if re.fullmatch('[0-9a-f]{40}', expected_sha or '') else None,
               'qualified': False, 'checks': {}, 'cleanupVerified': False,
               'actualOmp': False, 'R1': False, 'provider': False, 'productionSystemd': False,
               'Agents2Accepted': False}
    base = group_root = None
    base_inode = group_inode = None
    groups, threads = [], []
    phase = 'guard'
    try:
        base, group_root = guarded_paths(os.environ, expected_sha, platform=sys.platform, uid=os.geteuid())
        phase = 'checkout'
        repository = Path(__file__).resolve().parents[2]
        require(Path(os.environ.get('GITHUB_WORKSPACE', '/nonexistent')).resolve(strict=True) == repository)
        git = ['git', '-c', f'safe.directory={repository}', '-C', str(repository)]
        head = subprocess.check_output([*git, 'rev-parse', 'HEAD'], text=True).strip()
        require(head == expected_sha)
        require(subprocess.check_output([*git, 'status', '--porcelain', '--untracked-files=no'], text=True) == '')
        require('bridge' not in sys.modules)  # Run as a script, then import only the protected copy.
        phase = 'parentPermissions'
        protected(base.parent)
        phase = 'resourceAbsence'
        absent(base)
        absent(group_root)
        phase = 'prerequisites'
        receipt['checks']['cgroupV2'] = any(line.split()[4] == '/sys/fs/cgroup' and ' - cgroup2 ' in line for line in Path('/proc/self/mountinfo').read_text().splitlines())
        receipt['checks']['writableCgroupMount'] = bool(receipt['checks']['cgroupV2'] and not os.statvfs(group_root.parent).f_flag & os.ST_RDONLY)
        require(receipt['checks']['cgroupV2'] and receipt['checks']['writableCgroupMount'])
        require((group_root.parent / 'cgroup.procs').is_file() and (group_root.parent / 'cgroup.controllers').is_file())
        identities = unused_identities()
        receipt['checks'][phase] = True
        phase = 'resources'
        base.mkdir(mode=0o755)
        base_inode = base.stat().st_ino
        base.chmod(0o755)
        group_root.mkdir(mode=0o700)
        group_inode = group_root.stat().st_ino
        require((group_root / 'cgroup.kill').is_file())
        source = base / 'source' / 'bridge'
        source.mkdir(parents=True, mode=0o755)
        source.parent.chmod(0o755)
        source.chmod(0o755)
        for name in ('__init__.py', 'executor.py', 'store.py', 'network.py'):
            shutil.copyfile(repository / 'bridge' / name, source / name)
            (source / name).chmod(0o644)
        shutil.copyfile(repository / 'bridge/tests/linux_worker.py', source / 'linux_worker.py')
        (source / 'linux_worker.py').chmod(0o644)
        artifact = base / 'synthetic-artifact'
        artifact.write_bytes(b'CI-only fixture, not OMP; never executed')
        artifact.chmod(0o444)
        homes = [base / f'home-{value}' for value in identities]
        mapping = {}
        for number, (uid, home) in enumerate(zip(identities, homes), 1):
            home.mkdir(mode=0o700)
            (home / 'private-marker').write_text('synthetic')
            os.chown(home / 'private-marker', uid, uid)
            os.chown(home, uid, uid)
            mapping[str(number)] = {'uid': uid, 'gid': uid, 'home': str(home), 'runtime_qualified': True,
                'omp': str(artifact), 'omp_sha256': hashlib.sha256(artifact.read_bytes()).hexdigest(),
                'omp_revision': 'synthetic-ci-only', 'go_key': f'ci-only-not-a-provider-key-{number}'}
        config = base / 'identities.json'
        config.write_text(json.dumps(mapping))
        config.chmod(0o600)
        (base / 'state').mkdir(mode=0o700)
        sys.path.insert(0, str(source.parent))
        from bridge.executor import IsolatedExecutor
        from bridge.store import Store
        require(Path(sys.modules['bridge.executor'].__file__).resolve() == source / 'executor.py')
        require(Path(sys.modules['bridge.network'].__file__).resolve() == source / 'network.py')
        store = Store(base / 'state/runs.sqlite')
        executor = IsolatedExecutor(store)
        executor.cgroup_root = group_root
        actual_popen = subprocess.Popen

        def synthetic_popen(command, **kwargs):
            require(command == [sys.executable, '-m', 'bridge.worker'])
            return actual_popen([sys.executable, '-m', 'bridge.linux_worker'], **kwargs)

        with patch.dict(os.environ, {'TRADING_BRIDGE_IDENTITIES': str(config), 'KTH_CI_AMBIENT_CANARY': 'synthetic-private-parent'}), patch('bridge.executor.subprocess.Popen', side_effect=synthetic_popen):
            for number, uid in enumerate(identities, 1):
                phase = f'identity{number}'
                executor.admission(str(number))
                run_id = str(uuid4())
                body = {'session_id': str(uuid4()), 'prompt': 'synthetic isolation only', 'qualification': {
                    'uid': uid, 'gid': uid, 'credential_scope': number,
                    'other_home': str(homes[2 - number]), 'identity_map': str(config)}}
                store.create(str(number), run_id, body)
                group = group_root / ('run-' + run_id)
                groups.append(group)
                thread = threading.Thread(target=executor.run, args=(str(number), run_id, body), daemon=True)
                threads.append(thread)
                thread.start()
                def ready():
                    return next((event['payload'] for event in store.events(str(number), run_id) if event['type'] == 'fixture_ready'), None)
                require(wait_for(ready))
                observation = ready()
                require(all(observation['checks'].values()))
                receipt['checks'][phase] = observation['checks']
                store.request_cancel(str(number), run_id)
                executor.cancel(run_id)
                require(wait_for(lambda: not (group / 'cgroup.procs').read_text().strip() and dead(observation['child_pid'])))
                thread.join(8)
                require(not thread.is_alive())
                require(store.get(str(number), run_id)['status'] == 'cancelled')
                receipt['checks'][phase]['cgroupEmptyAndDescendantDead'] = True
                receipt['checks'][phase]['executorConfirmedCancelled'] = True
        receipt['qualified'] = True
    except Exception:
        receipt['checks'][phase] = False
    finally:
        try:
            if group_inode is not None:
                owned_created(group_root, group_inode)
                for group in groups:
                    if group.exists():
                        require(group.parent == group_root and re.fullmatch('run-[0-9a-f-]{36}', group.name) is not None)
                        require(not group.is_symlink() and group.stat().st_uid == 0)
                        (group / 'cgroup.kill').write_text('1')
                        require(wait_for(lambda: not (group / 'cgroup.procs').read_text().strip()))
            for thread in threads:
                thread.join(2)
                require(not thread.is_alive())
            if group_inode is not None:
                for group in groups:
                    if group.exists():
                        group.rmdir()
                group_root.rmdir()
            if base_inode is not None:
                owned_created(base, base_inode)
                shutil.rmtree(base)
            receipt['cleanupVerified'] = True
        except Exception:
            receipt['qualified'] = False
        print(json.dumps(receipt, sort_keys=True), flush=True)
    return 0 if receipt['qualified'] and receipt['cleanupVerified'] else 1


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--expected-sha', required=True)
    sys.exit(qualify(parser.parse_args().expected_sha))
