"""Pure guard checks: no root, cgroup, UID or production resource mutations."""
import io
import json
from contextlib import redirect_stdout
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from bridge.tests.qualify_linux import absent, guarded_paths, owned_created, protected, qualify


class LinuxQualificationGuards(unittest.TestCase):
    def setUp(self):
        self.sha = 'a' * 40
        self.env = {'GITHUB_ACTIONS': 'true', 'GITHUB_EVENT_NAME': 'workflow_dispatch',
                    'GITHUB_REPOSITORY': 'kernscale/Kernscale-Trading', 'GITHUB_REF': 'refs/heads/main',
                    'RUNNER_ENVIRONMENT': 'github-hosted', 'RUNNER_OS': 'Linux', 'GITHUB_SHA': self.sha,
                    'GITHUB_RUN_ID': '1234', 'GITHUB_RUN_ATTEMPT': '2'}

    def test_exact_paths_and_environment_required(self):
        self.assertEqual(guarded_paths(self.env, self.sha, platform='linux', uid=0),
            (Path('/opt/kth-ci-1234-2'), Path('/sys/fs/cgroup/kth-ci-1234-2')))
        for key in self.env:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                guarded_paths({**self.env, key: 'other'}, self.sha, platform='linux', uid=0)
        for platform, uid in (('darwin', 0), ('linux', 1000)):
            with self.assertRaises(RuntimeError):
                guarded_paths(self.env, self.sha, platform=platform, uid=uid)

    def test_traversal_and_noncanonical_numeric_names_refused(self):
        for name in ('../production', '0', '-1', '01', '1/../../x', '1\n', '9' * 21):
            for key in ('GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'):
                with self.subTest(name=name, key=key), self.assertRaises(RuntimeError):
                    guarded_paths({**self.env, key: name}, self.sha, platform='linux', uid=0)
        for sha in ('x', 'a' * 39, 'A' * 40, 'a' * 40 + '\n'):
            with self.assertRaises(RuntimeError):
                guarded_paths({**self.env, 'GITHUB_SHA': sha}, sha, platform='linux', uid=0)

    def test_existing_path_and_dangling_symlink_refused(self):
        for exists, symlink in ((True, False), (False, True), (True, True)):
            with self.subTest(exists=exists, symlink=symlink), self.assertRaises(RuntimeError):
                absent(Mock(exists=lambda: exists, is_symlink=lambda: symlink))
        absent(Mock(exists=lambda: False, is_symlink=lambda: False))

    def test_cleanup_requires_original_root_owned_inode(self):
        for uid, inode, symlink in ((1000, 2, False), (0, 3, False), (0, 2, True)):
            path = Mock(is_symlink=lambda: symlink, stat=lambda: SimpleNamespace(st_uid=uid, st_ino=inode))
            with self.subTest(uid=uid, inode=inode, symlink=symlink), self.assertRaises(RuntimeError):
                owned_created(path, 2)
        owned_created(Mock(is_symlink=lambda: False, stat=lambda: SimpleNamespace(st_uid=0, st_ino=2)), 2)

    def test_source_ancestor_permissions_and_symlinks_refused(self):
        path = Mock()
        path.absolute.return_value = path
        path.resolve.return_value = path
        path.parents = ()
        for uid, mode in ((1000, 0o755), (0, 0o777), (0, 0o775)):
            path.stat.return_value = SimpleNamespace(st_uid=uid, st_mode=mode)
            with self.assertRaises(RuntimeError):
                protected(path)
        path.stat.return_value = SimpleNamespace(st_uid=0, st_mode=0o755)
        protected(path)
        path.resolve.return_value = Mock()
        with self.assertRaises(RuntimeError):
            protected(path)

    def test_failed_guard_is_one_sanitized_unqualified_receipt_without_mutation(self):
        output = io.StringIO()
        with patch('bridge.tests.qualify_linux.guarded_paths', side_effect=RuntimeError('PRIVATE_MARKER')), \
                patch('bridge.tests.qualify_linux.Path.mkdir') as mkdir, redirect_stdout(output):
            self.assertEqual(qualify(self.sha), 1)
        mkdir.assert_not_called()
        self.assertEqual(len(output.getvalue().splitlines()), 1)
        receipt = json.loads(output.getvalue())
        self.assertFalse(receipt['qualified'])
        self.assertTrue(receipt['cleanupVerified'])
        self.assertEqual(receipt['checks'], {'guard': False})
        self.assertNotIn('PRIVATE_MARKER', output.getvalue())
        for key in ('actualOmp', 'R1', 'provider', 'productionSystemd', 'Agents2Accepted'):
            self.assertFalse(receipt[key])


if __name__ == '__main__':
    unittest.main()
