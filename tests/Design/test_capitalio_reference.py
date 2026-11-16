"""Keep the user's complete, selected registry source intact for parity review."""
import hashlib
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class CapitalioReferenceTest(unittest.TestCase):
    def test_all_twelve_original_registry_files_are_preserved(self):
        manifest = json.loads((ROOT / 'docs/design-references/capitalio-source-manifest.json').read_text())
        self.assertEqual(len(manifest['files']), 12)
        self.assertEqual(len({item['path'] for item in manifest['files']}), 12)
        for item in manifest['files']:
            with self.subTest(path=item['path']):
                source = ROOT / item['path']
                self.assertTrue(source.is_file(), 'A selected registry file is missing')
                self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(), item['sha256'])


if __name__ == '__main__':
    unittest.main()
