# CI Linux isolation qualification

This manual CI check qualifies actual Linux identity and cgroup behavior of the
unchanged `IsolatedExecutor`. It is not production admission, an OMP/R1 turn,
provider compatibility acceptance, or an Agents2/systemd confinement test.

Run only the dedicated `workflow_dispatch` workflow on the exact repository's
`main` ref and an ephemeral GitHub-hosted Ubuntu runner. Its scrubbed root command
executes the script directly (not `python -m`):

```text
/usr/bin/python3 -B bridge/tests/qualify_linux.py --expected-sha "$GITHUB_SHA"
```

The parent receives the existing declared HTTPX dependency through a disposable
runner dependency directory. `bridge/network.py` imports HTTPX, although no
network operation occurs. The admitted child receives only the executor's
sanitized environment and protected source PYTHONPATH; its fixture is stdlib-only.
No global packages, accounts, services, mounts, cgroup delegation, or production
settings are changed.

## Guards and disposable resources

Before mutation, the script checks Linux/root, exact GitHub repository/main/manual
event/hosted-runner metadata, canonical numeric run ID and attempt, expected SHA
against both GitHub SHA and clean checkout HEAD, and exact checkout workspace.
It refuses existing or dangling-symlink resource paths and unsafe `/var/lib`
ancestors. It probes a writable cgroup v2 mount and required files, then tests
actual creation and `cgroup.kill` availability. Unsupported prerequisites produce
an explicit nonzero, unqualified receipt; they do not silently skip or pass.

Only these exact disposable roots are created:

```text
/var/lib/kth-ci-<run-id>-<attempt>
/sys/fs/cgroup/kth-ci-<run-id>-<attempt>
```

The root-protected source copy contains unchanged executor/store/network modules.
The per-test identity map is root-owned mode 0600, admits two unused numeric
UID/GIDs checked against accounts, groups and live process identity fields, and
assigns each a UID-owned mode 0700 home. No `useradd` is used. Its
`runtime_qualified: true` is explicitly a synthetic-fixture assertion for this
disposable test only. The admitted artifact has a checked hash and is never
executed; it is not an OMP binary. Production guards and maps are untouched.

## Observations

The harness calls actual admission/run/cancel/fence methods. Its narrow Popen
wrapper asserts the original worker command and substitutes only the Python
fixture module, forwarding every actual environment, UID/GID, supplementary
group, cwd, session and umask keyword unchanged.

Each fixture waits for request stdin before forking, retaining the executor's
cgroup-before-dispatch gate. It verifies real UID/GID dropping, empty
supplementary groups, private owned home/cwd, umask, missing ambient canary,
synthetic-only admitted credential, denied cross-home reads, and denied identity
map reads. It creates a descendant with a separate POSIX session. Actual
cancellation must leave the cgroup empty, that descendant no longer live, the
executor thread exited, and the stored run terminally cancelled. Waiting is
bounded; asynchronous `cgroup.kill` completion is observed rather than assumed.
An `interrupted` result is a qualification failure, not relabeled success.

Cleanup kills and drains only recorded run cgroups, joins the executor before
removing those directories, and removes only roots created by this invocation.
Root ownership and original root inode checks prevent deleting foreign or
replaced resources. Any cleanup failure makes qualification false. No cleanup
mounts, remounts, scans/removes foreign groups, or broadens permissions on runner
directories.

One sanitized JSON receipt is printed in `finally`, with sourceCommit, qualified,
checks, cleanupVerified and explicit false actualOmp/R1/provider/productionSystemd/
Agents2Accepted fields. Exceptions, environment dumps and credentials are not
printed. Workflow setup failures before the script starts remain workflow
failures without a script receipt. Pure local guard checks are:

```text
python3 -m unittest bridge.tests.test_linux_qualification -v
```

These local checks exercise guards only and do not establish any Linux syscall
or target-host acceptance. The manual CI receipt supplies that separate evidence.
