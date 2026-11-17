"""Fixed app-only cgroup bootstrap/stop; no configurable paths or command runner."""
import fcntl
import json
import os
import stat
import sys
import time
from contextlib import contextmanager
from pathlib import Path
from uuid import UUID

from bridge.store import Store

CGROUP = Path('/sys/fs/cgroup/kernscale-trading')
DATABASE = Path('/var/lib/kernscale-trading-bridge/runs.sqlite')
DRAIN_SECONDS = 10


class UnsafeLifecycle(RuntimeError):
    pass


def require(condition, reason):
    if not condition:
        raise UnsafeLifecycle(reason)


def directory(path, *, private=False):
    for item in (path, *path.parents):
        info = item.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022, 'unsafe_directory')
    if private:
        require(stat.S_IMODE(path.lstat().st_mode) == 0o700, 'private_directory_required')


def regular(path, *, private=False):
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022, 'unsafe_file')
    if private:
        require(stat.S_IMODE(info.st_mode) == 0o600 and info.st_nlink == 1, 'private_file_required')


def host():
    require(sys.platform == 'linux' and os.geteuid() == 0, 'linux_root_required')
    require(os.environ.get('TRADING_BRIDGE_DB', str(DATABASE)) == str(DATABASE), 'fixed_database_required')
    source = Path(__file__).absolute()
    directory(source.parent)
    regular(source)
    directory(CGROUP.parent)
    require(any(line.split()[4] == str(CGROUP.parent) and ' - cgroup2 ' in line
                for line in Path('/proc/self/mountinfo').read_text().splitlines()), 'cgroup_v2_required')
    regular(CGROUP.parent / 'cgroup.procs')


def bootstrap():
    if not CGROUP.exists():
        require(not CGROUP.is_symlink(), 'symlink_refused')
        CGROUP.mkdir(mode=0o700)
    directory(CGROUP, private=True)
    regular(CGROUP / 'cgroup.procs')
    regular(CGROUP / 'cgroup.kill')
    return {'fenced': 0, 'reconciled': False}


def canonical_group(name):
    try:
        return name.startswith('run-') and str(UUID(name[4:])) == name[4:]
    except ValueError:
        return False


def groups():
    result = []
    for child in CGROUP.iterdir():
        info = child.lstat()
        require(not stat.S_ISLNK(info.st_mode), 'symlink_refused')
        if not stat.S_ISDIR(info.st_mode):
            continue  # Kernel-owned control files remain untouched.
        require(canonical_group(child.name), 'unexpected_group')
        directory(child, private=True)
        for entry in child.iterdir():
            mode = entry.lstat().st_mode
            require(not stat.S_ISDIR(mode) and not stat.S_ISLNK(mode), 'unexpected_nested_group')
        regular(child / 'cgroup.kill')
        regular(child / 'cgroup.procs')
        result.append(child)
    return result


def write_kill(group):
    descriptor = os.open(group / 'cgroup.kill', os.O_WRONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        os.write(descriptor, b'1')
    finally:
        os.close(descriptor)


def empty(group):
    descriptor = os.open(group / 'cgroup.procs', os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        return not os.read(descriptor, 1)
    finally:
        os.close(descriptor)


@contextmanager
def coordinator_lock():
    directory(DATABASE.parent, private=True)
    lock = Path(str(DATABASE) + '.lock')
    if lock.exists() or lock.is_symlink():
        regular(lock, private=True)
    descriptor = os.open(lock, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        regular(lock, private=True)
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        exists = DATABASE.exists()
        require(not DATABASE.is_symlink(), 'database_symlink_refused')
        if exists:
            regular(DATABASE, private=True)
            for suffix in ('-wal', '-shm'):
                path = Path(str(DATABASE) + suffix)
                if path.exists() or path.is_symlink():
                    regular(path, private=True)
        yield exists
    finally:
        os.close(descriptor)


def stop():
    with coordinator_lock() as database_exists:
        targets = []
        if CGROUP.exists() or CGROUP.is_symlink():
            directory(CGROUP, private=True)
            regular(CGROUP / 'cgroup.procs')
            regular(CGROUP / 'cgroup.kill')
            require(empty(CGROUP), 'unexpected_root_process')
            targets = groups()  # Validate EVERY child before touching any of them.
        deadline = time.monotonic() + DRAIN_SECONDS
        for group in targets:
            write_kill(group)
        for group in targets:
            while not empty(group):
                require(time.monotonic() < deadline, 'cgroup_drain_timeout')
                time.sleep(0.05)
            directory(group, private=True)
            group.rmdir()
        if database_exists:
            regular(DATABASE, private=True)  # No Store construction unless protected DB still exists.
            Store(DATABASE).reconcile()
        return {'fenced': len(targets), 'reconciled': database_exists}


def main(arguments=None):
    arguments = sys.argv[1:] if arguments is None else arguments
    if arguments not in (['bootstrap'], ['stop']):
        print(json.dumps({'ok': False, 'reason': 'fixed_action_required'}))
        return 2
    try:
        host()
        result = bootstrap() if arguments[0] == 'bootstrap' else stop()
        print(json.dumps({'ok': True, 'action': arguments[0], **result}))
        return 0
    except UnsafeLifecycle as error:
        print(json.dumps({'ok': False, 'action': arguments[0], 'reason': str(error)}))
        return 1
    except Exception:
        print(json.dumps({'ok': False, 'action': arguments[0], 'reason': 'host_lifecycle_unavailable'}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
