"""Offline lifecycle guards and fake cgroup files; never mutates host cgroups."""
import tempfile
import unittest
import io
import stat
import os
from contextlib import ExitStack, redirect_stdout
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from uuid import uuid4

from bridge.deploy import lifecycle
from bridge.store import Store


class HostLifecycleTests(unittest.TestCase):
    def test_unit_orders_bootstrap_and_post_stop_fencing(self):
        directory = Path(__file__).resolve().parents[1] / 'deploy'
        unit = (directory / 'kernscale-trading-bridge.service').read_text()
        self.assertIn('Requires=kernscale-trading-cgroup.service', unit)
        self.assertIn('ExecStopPost=/opt/kernscale/trading/venv/bin/python -m bridge.deploy.lifecycle stop', unit)
        for guard in ('ProtectSystem=strict', 'ProtectHome=true', 'NoNewPrivileges=true', 'KillMode=control-group'):
            self.assertIn(guard, unit)
        bootstrap = (directory / 'kernscale-trading-cgroup.service').read_text()
        self.assertIn('bridge.deploy.lifecycle bootstrap', bootstrap)
        self.assertIn('RemainAfterExit=yes', bootstrap)

    def test_linux_root_and_exact_action_are_required_before_mutation(self):
        for platform, uid in (('darwin', 0), ('linux', 1000)):
            with patch.object(lifecycle.sys, 'platform', platform), patch.object(lifecycle.os, 'geteuid', return_value=uid), self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.host()
        with patch.object(lifecycle, 'host') as host, redirect_stdout(io.StringIO()):
            self.assertEqual(lifecycle.main(['stop', '/arbitrary/path']), 2)
            self.assertEqual(lifecycle.main(['run', 'shell']), 2)
            host.assert_not_called()

    def test_canonical_uuid_names_only(self):
        identifier = str(uuid4())
        self.assertTrue(lifecycle.canonical_group('run-' + identifier))
        for name in ('run-' + identifier.upper(), 'other-' + identifier, 'run-../other', 'run-not-a-uuid'):
            self.assertFalse(lifecycle.canonical_group(name))

    def test_ownership_write_permissions_and_symlink_guards(self):
        path = SimpleNamespace(parents=(), lstat=lambda: SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_uid=0))
        lifecycle.directory(path, private=True)
        for mode, uid in ((stat.S_IFDIR | 0o777, 0), (stat.S_IFDIR | 0o700, 1000), (stat.S_IFLNK | 0o700, 0)):
            path.lstat = lambda: SimpleNamespace(st_mode=mode, st_uid=uid)
            with self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.directory(path)
        for mode, uid, links in ((stat.S_IFREG | 0o666, 0, 1), (stat.S_IFREG | 0o600, 1000, 1), (stat.S_IFLNK | 0o600, 0, 1), (stat.S_IFREG | 0o600, 0, 2)):
            path.lstat = lambda: SimpleNamespace(st_mode=mode, st_uid=uid, st_nlink=links)
            with self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.regular(path, private=True)

    def fake_resources(self, directory):
        stack = ExitStack()
        self.addCleanup(stack.close)
        root = Path(directory) / 'cgroup'
        root.mkdir()
        (Path(directory) / 'state').mkdir(mode=0o700)
        for filename in ('cgroup.procs', 'cgroup.kill'):
            (root / filename).write_text('')
        stack.enter_context(patch.object(lifecycle, 'CGROUP', root))
        stack.enter_context(patch.object(lifecycle, 'DATABASE', Path(directory) / 'state/runs.sqlite'))
        # Root ownership and kernel mount cannot be simulated on macOS. Guard behavior
        # is checked independently above; only these fake filesystem checks are bypassed.
        stack.enter_context(patch.object(lifecycle, 'directory'))
        stack.enter_context(patch.object(lifecycle, 'regular'))
        return root

    def test_bootstrap_never_constructs_database_and_validates_existing_root(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            with patch.object(lifecycle, 'Store') as store:
                self.assertEqual(lifecycle.bootstrap(), {'fenced': 0, 'reconciled': False})
                store.assert_not_called()
            self.assertTrue(root.exists())
            self.assertFalse(lifecycle.DATABASE.exists())
            for child in root.iterdir():
                child.unlink()
            root.rmdir()
            with patch.object(lifecycle, 'Store') as store:
                lifecycle.bootstrap()
                store.assert_not_called()
            self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o700)
            with patch.object(lifecycle, 'directory', side_effect=lifecycle.UnsafeLifecycle('unsafe_directory')), patch.object(lifecycle, 'Store') as store:
                with self.assertRaises(lifecycle.UnsafeLifecycle):
                    lifecycle.bootstrap()
                store.assert_not_called()
            self.assertFalse(lifecycle.DATABASE.exists())

    def test_missing_or_unsafe_state_parent_prevents_unlocked_fencing(self):
        with tempfile.TemporaryDirectory() as directory:
            original_directory = lifecycle.directory
            root = self.fake_resources(directory)
            group = root / ('run-' + str(uuid4()))
            group.mkdir()
            (group / 'cgroup.kill').write_text('')
            (group / 'cgroup.procs').write_text('')
            lifecycle.DATABASE.parent.rmdir()
            with patch.object(lifecycle, 'directory', original_directory), self.assertRaises(FileNotFoundError):
                lifecycle.stop()
            with patch.object(lifecycle, 'directory', side_effect=lifecycle.UnsafeLifecycle('unsafe_directory')), self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.stop()
            self.assertEqual((group / 'cgroup.kill').read_text(), '')
            self.assertFalse(lifecycle.DATABASE.exists())

    def test_stop_without_database_never_creates_it_and_retains_bootstrap_root(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            with patch.object(lifecycle, 'Store') as store:
                self.assertEqual(lifecycle.stop(), {'fenced': 0, 'reconciled': False})
                store.assert_not_called()
            self.assertTrue(root.exists())
            self.assertFalse(lifecycle.DATABASE.exists())
            self.assertEqual(stat.S_IMODE(Path(str(lifecycle.DATABASE) + '.lock').stat().st_mode), 0o600)

    def test_unknown_group_or_symlink_prevents_all_fencing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            valid = root / ('run-' + str(uuid4()))
            valid.mkdir()
            (valid / 'cgroup.procs').write_text('')
            (valid / 'cgroup.kill').write_text('')
            unexpected = root / 'foreign-group'
            unexpected.mkdir()
            with self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.stop()
            self.assertEqual((valid / 'cgroup.kill').read_text(), '')
            unexpected.rmdir()
            (root / 'foreign-link').symlink_to(valid)
            with self.assertRaises(lifecycle.UnsafeLifecycle):
                lifecycle.stop()
            self.assertEqual((valid / 'cgroup.kill').read_text(), '')

    def test_actual_store_reconciliation_only_after_fencing_and_observed_drain(self):
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            database = lifecycle.DATABASE
            store = Store(database)
            run = str(uuid4())
            store.create('1', run, {'session_id': str(uuid4()), 'prompt': 'private prompt'})
            group = root / ('run-' + run)
            group.mkdir()
            (group / 'cgroup.procs').write_text('')
            (group / 'cgroup.kill').write_text('')
            original_rmdir = Path.rmdir

            def kernel_rmdir(path):
                self.assertEqual(path, group)
                self.assertEqual((path / 'cgroup.kill').read_text(), '1')
                for child in path.iterdir():
                    child.unlink()  # Real cgroup interface files are removed by the kernel.
                original_rmdir(path)

            with patch.object(Path, 'rmdir', kernel_rmdir):
                self.assertEqual(lifecycle.stop(), {'fenced': 1, 'reconciled': True})
            self.assertTrue(root.exists())
            self.assertFalse(group.exists())
            self.assertEqual(store.get('1', run)['status'], 'interrupted')

    def test_live_coordinator_lock_and_drain_timeout_prevent_reconciliation(self):
        import fcntl
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            Store(lifecycle.DATABASE)
            with open(str(lifecycle.DATABASE) + '.lock', 'a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaises(BlockingIOError), patch.object(lifecycle, 'Store') as store:
                    lifecycle.stop()
                store.assert_not_called()
            group = root / ('run-' + str(uuid4()))
            group.mkdir()
            (group / 'cgroup.procs').write_text('123\n')
            (group / 'cgroup.kill').write_text('')
            with patch.object(lifecycle.time, 'monotonic', side_effect=[0, 11]), patch.object(lifecycle, 'Store') as store:
                with self.assertRaisesRegex(lifecycle.UnsafeLifecycle, 'cgroup_drain_timeout'):
                    lifecycle.stop()
                store.assert_not_called()
            self.assertTrue(group.exists())
            self.assertEqual((group / 'cgroup.kill').read_text(), '1')

    def test_wrong_database_environment_refuses_fencing_before_filesystem_actions(self):
        with patch.object(lifecycle.sys, 'platform', 'linux'), patch.object(lifecycle.os, 'geteuid', return_value=0), \
                patch.dict(os.environ, {'TRADING_BRIDGE_DB': '/different.sqlite'}), \
                patch.object(lifecycle, 'directory') as directory, patch.object(lifecycle, 'write_kill') as kill, redirect_stdout(io.StringIO()):
            self.assertEqual(lifecycle.main(['stop']), 1)
            directory.assert_not_called()
            kill.assert_not_called()

    def test_missing_database_still_requires_exclusive_coordinator_lock(self):
        import fcntl
        with tempfile.TemporaryDirectory() as directory:
            root = self.fake_resources(directory)
            group = root / ('run-' + str(uuid4()))
            group.mkdir()
            (group / 'cgroup.procs').write_text('')
            (group / 'cgroup.kill').write_text('')
            with open(str(lifecycle.DATABASE) + '.lock', 'a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaises(BlockingIOError), patch.object(lifecycle, 'Store') as store:
                    lifecycle.stop()
                store.assert_not_called()
            self.assertEqual((group / 'cgroup.kill').read_text(), '')
            self.assertFalse(lifecycle.DATABASE.exists())


if __name__ == '__main__':
    unittest.main()
