"""Provider-free CI fixture; never an application worker or selectable over HTTP."""
import json
import os
import signal
import sys


def denied(path):
    try:
        with open(path, 'rb'):
            return False
    except PermissionError:
        return True


def main():
    # The actual executor writes stdin only after placing this process in its cgroup.
    request = json.loads(sys.stdin.readline())
    fixture = request['qualification']
    home = os.environ['HOME']
    mask = os.umask(0o077)
    os.umask(mask)
    checks = {'uid': os.getuid() == fixture['uid'], 'gid': os.getgid() == fixture['gid'],
              'supplementaryGroupsEmpty': os.getgroups() == [], 'umask': mask == 0o077,
              'privateHome': os.stat(home).st_mode & 0o077 == 0,
              'homeOwned': os.stat(home).st_uid == os.getuid(), 'cwd': os.getcwd() == home,
              'ambientCanaryAbsent': 'KTH_CI_AMBIENT_CANARY' not in os.environ,
              'syntheticCredentialOnly': os.environ.get('OPENCODE_API_KEY') == f"ci-only-not-a-provider-key-{fixture['credential_scope']}",
              'otherHomeDenied': denied(fixture['other_home'] + '/private-marker'),
              'identityMapDenied': denied(fixture['identity_map'])}
    read_end, write_end = os.pipe()
    child = os.fork()
    if child == 0:
        os.close(read_end)
        os.setsid()
        os.write(write_end, b'1')
        os.close(write_end)
        while True:
            signal.pause()
    os.close(write_end)
    checks['descendantDetached'] = os.read(read_end, 1) == b'1' and os.getsid(child) == child
    os.close(read_end)
    print(json.dumps({'type': 'clarification_requested', 'payload': {
        'request_id': fixture['request_id'], 'tool_call_id': 'ci-clarification',
        'questions': [{'id': '0', 'header': 'Research', 'question': 'Which scope?',
                       'options': [{'label': 'Global', 'description': ''},
                                   {'label': 'Regional', 'description': ''}]}]}}), flush=True)
    print(json.dumps({'type': 'fixture_ready', 'payload': {'checks': checks, 'child_pid': child}}), flush=True)
    while True:
        signal.pause()


if __name__ == '__main__':
    main()
