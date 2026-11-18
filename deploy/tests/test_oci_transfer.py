import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import tarfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('oci_transfer', Path(__file__).parents[1] / 'oci_transfer.py')
transfer = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(transfer)


class TransferTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'blobs/sha256').mkdir(parents=True)
        (self.root / 'oci-layout').write_text('{"imageLayoutVersion":"1.0.0"}')
        self.commit = 'a' * 40
        labels = {'org.opencontainers.image.revision': self.commit,
                  'org.opencontainers.image.source': 'https://github.com/kernscale/Kernscale-Trading',
                  'org.opencontainers.image.ref.name': 'main',
                  'org.opencontainers.image.version': 'sha-' + self.commit,
                  'org.opencontainers.image.created': '2026-11-17T00:00:00Z'}
        config = self.blob({'os': 'linux', 'architecture': 'amd64',
                            'config': {'User': 'kernscale', 'Labels': labels}},
                           'application/vnd.oci.image.config.v1+json')
        self.layer = self.blob({'synthetic': True}, 'application/vnd.oci.image.layer.v1.tar')
        manifest = self.blob({'schemaVersion': 2, 'config': config, 'layers': [self.layer]}, transfer.MANIFEST)
        self.target = self.blob({'schemaVersion': 2, 'manifests': [manifest]}, transfer.INDEX)
        self.wrapper = {'schemaVersion': 2, 'manifests': [self.target]}
        self.write_wrapper()

    def blob(self, data, media):
        raw = json.dumps(data).encode()
        digest = hashlib.sha256(raw).hexdigest()
        (self.root / 'blobs/sha256' / digest).write_bytes(raw)
        return {'digest': 'sha256:' + digest, 'size': len(raw), 'mediaType': media}

    def write_wrapper(self):
        (self.root / 'index.json').write_text(json.dumps(self.wrapper))

    def verify(self):
        return transfer.verify_layout(self.root, self.target['digest'], self.commit)

    def test_original_target_unchanged_after_name_annotation(self):
        before = {p.name: p.read_bytes() for p in (self.root / 'blobs/sha256').iterdir()}
        self.target['annotations'] = {'io.containerd.image.name': transfer.REPOSITORY + '@' + self.target['digest']}
        self.write_wrapper()
        wrapper, _, receipt = self.verify()
        self.assertEqual(wrapper['manifests'][0]['digest'], self.target['digest'])
        self.assertEqual(receipt['verifiedReferencedBlobs'], 4)
        self.assertEqual(before, {p.name: p.read_bytes() for p in (self.root / 'blobs/sha256').iterdir()})

    def test_wrong_source(self):
        with self.assertRaises(ValueError):
            transfer.verify_layout(self.root, self.target['digest'], 'b' * 40)

    def test_wrong_target(self):
        with self.assertRaises(ValueError):
            transfer.verify_layout(self.root, 'sha256:' + '0' * 64, self.commit)

    def test_corrupt_and_missing_blob(self):
        path = self.root / 'blobs/sha256' / self.layer['digest'][7:]
        path.write_bytes(b'corrupt')
        with self.assertRaises(ValueError):
            self.verify()
        path.unlink()
        with self.assertRaises(ValueError):
            self.verify()

    def test_symlink_and_unexpected_path(self):
        path = self.root / 'credentials'
        path.symlink_to('/etc/passwd')
        with self.assertRaises(ValueError):
            self.verify()
        path.unlink()
        path.write_text('not an image blob')
        with self.assertRaises(ValueError):
            self.verify()

    def test_path_escape(self):
        self.target['digest'] = 'sha256:../../outside'
        self.write_wrapper()
        with self.assertRaises(ValueError):
            self.verify()

    def test_wrong_descriptor_size(self):
        self.target['size'] += 1
        self.write_wrapper()
        with self.assertRaises(ValueError):
            self.verify()

    def test_package_preserves_original_blobs_and_contains_only_layout(self):
        before = {p.name: p.read_bytes() for p in (self.root / 'blobs/sha256').iterdir()}
        original_verify = transfer.verify_layout
        output = self.root.parent / (self.root.name + '-transfer')
        self.addCleanup(lambda: __import__('shutil').rmtree(output, ignore_errors=True))
        with patch.object(transfer, 'verify_layout', side_effect=lambda root:
                          original_verify(root, self.target['digest'], self.commit)):
            transfer.package(self.root, output)
        with tarfile.open(output / 'image.oci.tar') as archive:
            self.assertTrue(all(item.isfile() for item in archive.getmembers()))
            wrapper = json.load(archive.extractfile('index.json'))
            self.assertEqual(wrapper['manifests'][0]['digest'], self.target['digest'])
            self.assertEqual(wrapper['manifests'][0]['annotations']['io.containerd.image.name'],
                             transfer.REPOSITORY + '@' + self.target['digest'])
            for digest, raw in before.items():
                self.assertEqual(archive.extractfile('blobs/sha256/' + digest).read(), raw)
        self.assertEqual(set(p.name for p in output.iterdir()),
                         {'image.oci.tar', 'receipt.json', 'SHA256SUMS'})
        receipt = json.loads((output / 'receipt.json').read_text())
        self.assertFalse(receipt['runtimeAccepted'])
        self.assertEqual(receipt['archiveSha256'], transfer.sha(output / 'image.oci.tar'))


if __name__ == '__main__':
    unittest.main()
