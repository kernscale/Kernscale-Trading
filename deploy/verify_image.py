#!/usr/bin/env python3
"""Verify private package metadata against a pulled immutable image's actual config."""
import argparse
from datetime import datetime
import json
from pathlib import Path
import re

REPOSITORY = 'kernscale/Kernscale-Trading'
IMAGE = r'ghcr\.io/kernscale/kernscale-trading@sha256:[a-f0-9]{64}'


def verify(package, inspected, image, commit, created):
    def require(condition, message):
        if not condition:
            raise ValueError(message)

    require(isinstance(image, str) and re.fullmatch(IMAGE, image), 'Exact immutable application image required')
    require(isinstance(commit, str) and re.fullmatch(r'[a-f0-9]{40}', commit), 'Exact source commit required')
    try:
        datetime.strptime(created, '%Y-%m-%dT%H:%M:%SZ')
    except (ValueError, TypeError):
        raise ValueError('Exact UTC build timestamp required') from None
    require(isinstance(package, dict) and package.get('name') == 'kernscale-trading'
            and package.get('visibility') == 'private', 'Exact private package required')
    repository = package.get('repository')
    require(repository is None or (isinstance(repository, str) and repository.lower() == REPOSITORY.lower()),
            'Present REST repository association must match the owning repository')
    require(isinstance(inspected, dict) and set(inspected) == {'RepoDigests', 'Architecture', 'Os', 'Config'},
            'Whitelisted actual image inspection required')
    require(isinstance(inspected['RepoDigests'], list) and image in inspected['RepoDigests'],
            'Pulled image digest differs')
    require(inspected['Architecture'] == 'amd64' and inspected['Os'] == 'linux', 'Linux amd64 image required')
    config = inspected['Config']
    require(isinstance(config, dict) and set(config) == {'User', 'Labels'}, 'Whitelisted actual image config required')
    require(config['User'] in ('kernscale', '10001'), 'Expected nonroot runtime user required')
    labels = config['Labels']
    expected = {'org.opencontainers.image.revision': commit,
                'org.opencontainers.image.source': 'https://github.com/' + REPOSITORY,
                'org.opencontainers.image.ref.name': 'main',
                'org.opencontainers.image.created': created,
                'org.opencontainers.image.version': 'sha-' + commit}
    require(isinstance(labels, dict) and all(labels.get(key) == value for key, value in expected.items()),
            'Actual source labels differ from the approved candidate')
    return {'image': image, 'sourceCommit': commit, 'privatePackage': True,
            'repositoryAssociation': 'omitted-by-rest-api' if repository is None else 'matched',
            'actualImageConfigVerified': True, 'runtimeAccepted': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    parser.add_argument('inspection', type=Path)
    parser.add_argument('image')
    parser.add_argument('commit')
    parser.add_argument('created')
    args = parser.parse_args()
    try:
        result = verify(json.loads(args.package.read_text()), json.loads(args.inspection.read_text()),
                        args.image, args.commit, args.created)
    except (ValueError, TypeError, KeyError, OSError):
        raise SystemExit('Private package or actual immutable image verification failed') from None
    print(json.dumps(result, indent=2))
