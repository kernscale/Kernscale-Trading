import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from bridge.worker import owned_session_file


class SessionPathTests(unittest.TestCase):
    def test_allocated_initial_path_requires_private_owned_existing_parent(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            allocated = root / 'allocated.jsonl'
            self.assertEqual(owned_session_file(root, allocated, allow_allocated=True), allocated.resolve())
            self.assertFalse(allocated.exists())
            with self.assertRaises(FileNotFoundError):
                owned_session_file(root, allocated)
            with self.assertRaises(FileNotFoundError):
                owned_session_file(root, root / 'missing' / 'allocated.jsonl', allow_allocated=True)
            root.chmod(0o755)
            with self.assertRaises(RuntimeError):
                owned_session_file(root, allocated, allow_allocated=True)

    def test_existing_resume_requires_regular_owned_jsonl_without_symlinks_or_escape(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            existing = root / 'saved.jsonl'
            existing.write_text('actual saved fixture\n')
            self.assertEqual(owned_session_file(root, existing), existing)
            for value in (root, root.parent / 'escape.jsonl', root / '..' / 'escape.jsonl'):
                with self.subTest(value=value), self.assertRaises((RuntimeError, ValueError)):
                    owned_session_file(root, value, allow_allocated=True)
            link = root / 'link.jsonl'
            link.symlink_to(existing)
            with self.assertRaises(RuntimeError):
                owned_session_file(root, link, allow_allocated=True)
            missing_link = root / 'dangling.jsonl'
            missing_link.symlink_to(root / 'absent.jsonl')
            with self.assertRaises(RuntimeError):
                owned_session_file(root, missing_link, allow_allocated=True)
            subdir = root / 'linked-parent'
            subdir.symlink_to(root, target_is_directory=True)
            with self.assertRaises(RuntimeError):
                owned_session_file(subdir, subdir / 'allocated.jsonl', allow_allocated=True)
            with self.assertRaises(RuntimeError):
                owned_session_file(root, subdir / 'allocated.jsonl', allow_allocated=True)
            original = Path.lstat

            def foreign(path):
                info = original(path)
                if path == existing:
                    fields = list(info)
                    fields[4] = os.getuid() + 1
                    return os.stat_result(fields)
                return info

            with patch.object(Path, 'lstat', foreign), self.assertRaises(RuntimeError):
                owned_session_file(root, existing)
            existing.chmod(0o666)
            with self.assertRaises(RuntimeError):
                owned_session_file(root, existing)
