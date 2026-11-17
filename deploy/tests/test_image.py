import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('verify_image', Path(__file__).parents[1] / 'verify_image.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class ImageEvidenceTest(unittest.TestCase):
    image = 'ghcr.io/kernscale/kernscale-trading@sha256:' + 'a' * 64
    commit = 'b' * 40
    created = '2026-11-17T00:00:00Z'

    def package(self):
        return {'name': 'kernscale-trading', 'visibility': 'private', 'repository': None}

    def inspection(self):
        return {'RepoDigests': [self.image], 'Architecture': 'amd64', 'Os': 'linux',
                'Config': {'User': 'kernscale', 'Labels': {
                    'org.opencontainers.image.revision': self.commit,
                    'org.opencontainers.image.source': 'https://github.com/kernscale/Kernscale-Trading',
                    'org.opencontainers.image.ref.name': 'main',
                    'org.opencontainers.image.created': self.created,
                    'org.opencontainers.image.version': 'sha-' + self.commit}}}

    def check(self, package=None, inspection=None):
        return m.verify(self.package() if package is None else package,
                        self.inspection() if inspection is None else inspection,
                        self.image, self.commit, self.created)

    def test_private_exact_image_with_nullable_or_matching_repository(self):
        self.assertEqual(self.check()['repositoryAssociation'], 'omitted-by-rest-api')
        package = self.package()
        package['repository'] = 'KERNSCALE/kernscale-trading'
        self.assertEqual(self.check(package)['repositoryAssociation'], 'matched')
        package.pop('repository')
        self.assertEqual(self.check(package)['repositoryAssociation'], 'omitted-by-rest-api')
        self.assertFalse(self.check()['runtimeAccepted'])
        inspected = self.inspection()
        inspected['Config']['User'] = '10001'
        self.assertTrue(self.check(inspection=inspected)['actualImageConfigVerified'])

    def test_wrong_or_public_package_rejected(self):
        for key, value in [('name', 'another-package'), ('visibility', 'public'),
                           ('visibility', 'internal'), ('repository', 'kernscale/other'),
                           ('repository', ''), ('repository', {})]:
            package = self.package()
            package[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                self.check(package)

    def test_actual_digest_platform_and_root_rejected(self):
        for key, value in [('RepoDigests', ['ghcr.io/kernscale/kernscale-trading@sha256:' + 'c' * 64]),
                           ('RepoDigests', []), ('Architecture', 'arm64'), ('Os', 'windows')]:
            inspected = self.inspection()
            inspected[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.check(inspection=inspected)
        for user in ['', 'root', '0', '0:0', 'other', '10001:0']:
            inspected = self.inspection()
            inspected['Config']['User'] = user
            with self.subTest(user=user), self.assertRaises(ValueError):
                self.check(inspection=inspected)

    def test_every_actual_source_label_must_match(self):
        for label in self.inspection()['Config']['Labels']:
            for value in [None, 'wrong']:
                inspected = copy.deepcopy(self.inspection())
                if value is None:
                    inspected['Config']['Labels'].pop(label)
                else:
                    inspected['Config']['Labels'][label] = value
                with self.subTest(label=label, value=value), self.assertRaises(ValueError):
                    self.check(inspection=inspected)

    def test_expected_identity_cannot_be_mutable_or_malformed(self):
        with self.assertRaises(ValueError):
            m.verify(self.package(), self.inspection(), 'app:latest', self.commit, self.created)
        with self.assertRaises(ValueError):
            m.verify(self.package(), self.inspection(), self.image, 'main', self.created)


if __name__ == '__main__':
    unittest.main()
