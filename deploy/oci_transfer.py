#!/usr/bin/env python3
"""Package the fixed reviewed OCI graph; never include registry credentials."""
import hashlib
import json
from pathlib import Path
import re
import sys
import tarfile

DIGEST = 'sha256:160bdcbd2bad1e4f025ad4bc5cd49dec763ab2976af14848fbc2e4a381fe7a62'
COMMIT = 'cc73d149fe3e783a3631c4fd9ee599894c1fff92'
REPOSITORY = 'ghcr.io/kernscale/kernscale-trading'
INDEX = 'application/vnd.oci.image.index.v1+json'
MANIFEST = 'application/vnd.oci.image.manifest.v1+json'


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def verify_layout(layout, expected=DIGEST, commit=COMMIT):
    """Validate every stored blob and recursively traverse the original graph."""
    require(layout.is_dir() and not layout.is_symlink(), 'Unsafe layout root')
    files = []
    for path in layout.rglob('*'):
        require(not path.is_symlink(), 'Symlinks forbidden')
        relative = path.relative_to(layout).as_posix()
        if path.is_dir():
            require(relative in ('blobs', 'blobs/sha256'), 'Unexpected directory')
            continue
        require(path.is_file(), 'Nonregular file forbidden')
        require(relative in ('index.json', 'oci-layout') or
                re.fullmatch(r'blobs/sha256/[a-f0-9]{64}', relative), 'Unexpected layout path')
        if relative.startswith('blobs/'):
            require(sha(path) == path.name, 'Stored blob checksum differs')
        files.append(path)
    require(json.loads((layout / 'oci-layout').read_bytes()) == {'imageLayoutVersion': '1.0.0'},
            'Unsupported OCI layout')
    wrapper = json.loads((layout / 'index.json').read_bytes())
    require(wrapper.get('schemaVersion') == 2 and len(wrapper.get('manifests', [])) == 1,
            'Single original target required')
    target = wrapper['manifests'][0]
    require(target.get('digest') == expected, 'Original target digest differs')
    seen = set()
    configs = []

    def visit(descriptor):
        digest = descriptor.get('digest', '')
        require(isinstance(digest, str) and re.fullmatch(r'sha256:[a-f0-9]{64}', digest),
                'Unsafe descriptor digest')
        path = layout / 'blobs' / 'sha256' / digest[7:]
        require(path.is_file() and not path.is_symlink(), 'Referenced blob missing')
        require(type(descriptor.get('size')) is int and descriptor['size'] == path.stat().st_size,
                'Referenced blob size differs')
        require(sha(path) == digest[7:], 'Referenced blob checksum differs')
        if digest in seen:
            return
        seen.add(digest)
        media = descriptor.get('mediaType')
        if media == INDEX:
            data = json.loads(path.read_bytes())
            require(data.get('schemaVersion') == 2 and isinstance(data.get('manifests'), list),
                    'Invalid original index')
            for child in data['manifests']:
                visit(child)
        elif media == MANIFEST:
            data = json.loads(path.read_bytes())
            require(data.get('schemaVersion') == 2 and isinstance(data.get('layers'), list),
                    'Invalid manifest')
            visit(data['config'])
            configs.append(json.loads((layout / 'blobs' / 'sha256' /
                                       data['config']['digest'][7:]).read_bytes()))
            for child in data['layers']:
                visit(child)

    require(target.get('mediaType') in (INDEX, MANIFEST), 'OCI index or manifest required')
    visit(target)
    runtime = [config for config in configs if config.get('os') == 'linux'
               and config.get('architecture') == 'amd64']
    require(len(runtime) == 1, 'One Linux amd64 runtime config required')
    config = runtime[0].get('config', {})
    require(config.get('User') == 'kernscale', 'Reviewed runtime user required')
    labels = config.get('Labels', {})
    expected_labels = {'org.opencontainers.image.revision': commit,
                       'org.opencontainers.image.source': 'https://github.com/kernscale/Kernscale-Trading',
                       'org.opencontainers.image.ref.name': 'main',
                       'org.opencontainers.image.version': 'sha-' + commit}
    require(all(labels.get(key) == value for key, value in expected_labels.items()),
            'Runtime source labels differ')
    require(isinstance(labels.get('org.opencontainers.image.created'), str)
            and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ',
                             labels['org.opencontainers.image.created']), 'Build time required')
    return wrapper, files, {'image': REPOSITORY + '@' + expected, 'sourceCommit': commit,
                            'verifiedReferencedBlobs': len(seen), 'storedBlobs': sum(
                                path.parent.name == 'sha256' for path in files),
                            'actualConfigUser': config['User'],
                            'identityBasis': 'Fixed reviewed OCI graph; named user alone does not prove UID',
                            'runtimeAccepted': False}


def package(layout, output):
    wrapper, files, receipt = verify_layout(layout)
    require(not output.exists(), 'Output must be new')
    require(layout.resolve() != output.resolve() and layout.resolve() not in output.resolve().parents,
            'Output must be outside layout')
    wrapper['manifests'][0].setdefault('annotations', {})['io.containerd.image.name'] = receipt['image']
    (layout / 'index.json').write_text(json.dumps(wrapper, separators=(',', ':')) + '\n')
    # Recheck after the only permitted mutation; original content digests are unchanged.
    verify_layout(layout)
    output.mkdir(mode=0o700, parents=True)
    archive = output / 'image.oci.tar'
    with tarfile.open(archive, 'w', format=tarfile.PAX_FORMAT) as stream:
        for path in sorted(files):
            stream.add(path, arcname=path.relative_to(layout).as_posix(), recursive=False)
    receipt['archiveSha256'] = sha(archive)
    (output / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
    (output / 'SHA256SUMS').write_text(''.join(
        sha(output / name) + '  ' + name + '\n' for name in ('image.oci.tar', 'receipt.json')))


if __name__ == '__main__':
    try:
        require(len(sys.argv) == 3, 'Expected layout and new output directory')
        package(Path(sys.argv[1]), Path(sys.argv[2]))
    except (ValueError, OSError, KeyError, TypeError, RecursionError):
        raise SystemExit('Fixed OCI transfer verification failed') from None
