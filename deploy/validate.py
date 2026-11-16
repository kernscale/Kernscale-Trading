#!/usr/bin/env python3
"""Resolve Compose offline without printing protected runtime environment values."""
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent


def resolved(environment=None):
    result = []
    for name in ('compose.yaml', 'state.compose.yaml'):
        command = ['docker', 'compose', '-f', str(ROOT / name), '--profile', 'workers', 'config', '--format', 'json']
        process = subprocess.run(command, env=environment, check=True, capture_output=True, text=True)
        result.append(json.loads(process.stdout))
    return result


def validate(app, state):
    def require(condition, message):
        if not condition:
            raise ValueError(message)
    require(app['name'] in ('kernscale-trading-hub-blue', 'kernscale-trading-hub-green'), 'Reviewed slot required')
    require(state['name'] == 'kernscale-trading-hub-state', 'Fixed state project required')
    require(set(app['services']) == {'app', 'queue', 'scheduler'}, 'Exact app services required')
    require(set(state['services']) == {'postgres', 'redis'}, 'Exact external state services required')
    image = app['services']['app']['image']
    require(re.fullmatch(r'ghcr\.io/kernscale/kernscale-trading@sha256:[a-f0-9]{64}', image), 'Immutable literal app image required')
    for name, service in app['services'].items():
        require(service['image'] == image and service['user'] == '10001:10001', 'Shared nonroot app image required')
        require(service.get('read_only') is True, 'Read-only app filesystem required')
        expected = {'data', 'egress', 'ingress'} if name == 'app' else {'data', 'egress'}
        require(set(service['networks']) == expected, 'Unexpected app network membership')
        environment = service['environment']
        require(environment['KERNSCALE_RELEASE_IMAGE'] == image, 'Release identity differs')
        require(re.fullmatch(r'[a-f0-9]{40}', environment['KERNSCALE_RELEASE_COMMIT']), 'Source commit required')
        if name != 'app':
            require(service.get('profiles') == ['workers'], 'Workers require explicit singleton promotion')
    for name, major in [('postgres', '17'), ('redis', '7')]:
        service = state['services'][name]
        require(re.fullmatch(name + ':' + major + r'@sha256:[a-f0-9]{64}', service['image']), 'Reviewed state image major and digest required')
        require(set(service['networks']) == {'data'}, 'State must stay on private data network')
    for project in (app, state):
        for service in project['services'].values():
            require(not service.get('ports') and not service.get('privileged'), 'Published ports and privilege forbidden')
        for volume in project['volumes'].values():
            require(volume.get('external') is True, 'State must remain outside slots')
        for network in project['networks'].values():
            require(network.get('external') is True, 'Networks require separate host admission')
    return True


if __name__ == '__main__':
    try:
        validate(*resolved())
    except (subprocess.SubprocessError, ValueError, KeyError, TypeError):
        raise SystemExit('Runtime validation failed; inspect protected configuration locally without sharing secrets') from None
    print('Offline runtime contract valid; image build and Ops admission remain unverified')
