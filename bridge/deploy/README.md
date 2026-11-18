# Fixed host lifecycle

These units remain host admission templates. Install and verify the root-protected
application source/venv first; no helper accepts a path, environment-selected
resource, shell command, or arbitrary executor. Only `bootstrap` and `stop` are
valid actions for `python -m bridge.deploy.lifecycle`.

Install both `kernscale-trading-cgroup.service` and
`kernscale-trading-bridge.service`. The bridge requires the bootstrap oneshot
before its mount namespace is constructed, so its existing cgroup ReadWritePaths
target exists. Bootstrap creates only `/sys/fs/cgroup/kernscale-trading`, mode
0700, on an already mounted cgroup v2 filesystem. It does not mount/remount or
delegate cgroups, create a database, allocate identities, or install software.
The bootstrap's write exception is the cgroup parent solely to create this exact
literal child. Its action/code must remain root-protected and hash-qualified.

Workers reside in that fixed subtree, outside the coordinator unit's own cgroup.
Consequently KillMode=control-group remains useful but cannot fence those workers
alone. ExecStopPost invokes the fixed stop helper after the main process exits.
The 30-second service stop limit bounds the coordinator's graceful wait for live
workers before systemd terminates it and runs the post-stop helper. The helper
signals every prevalidated canonical `run-<UUID>` child with cgroup.kill, observes
actual empty cgroup.procs with a global 10-second drain deadline, then removes
the empty run directories. Unknown directories, nested groups, symlinks, unsafe
ownership/write modes, or unexpected processes directly in the app root cause
failure, never a broader cleanup. No PID-based foreign-process killing occurs.

The app root stays present after stop: the oneshot uses RemainAfterExit=yes,
and the same prepared root must support bridge restarts. After a host reboot,
the oneshot recreates this virtual directory before the bridge starts again.

The coordinator database is the fixed
`/var/lib/kernscale-trading-bridge/runs.sqlite`. Default production app startup
rejects any other configured path before constructing Store; injected offline
fixture stores remain available only through the Python test seam. The helper
also rejects a mismatched TRADING_BRIDGE_DB before filesystem actions. It requires
the already-existing root-owned private 0700 state directory and always takes
the fixed coordinator's nonblocking exclusive lock before fencing, even when the
database is absent. It can create only that root-owned mode-0600 lock file. If the
database exists, its mode-0600 regular file and SQLite sidecars are validated too.
A live coordinator/unsafe file blocks cleanup rather than racing its runs. After
all groups are drained and removed, existing Store.reconcile marks unfinished
runs/clarifications interrupted without replaying prompts. No Store is constructed
when the protected DB is absent; failed bootstrap never creates it. A missing or
unsafe state parent refuses fencing instead of performing unlocked cleanup. Existing
manual startup lock files must already have the correct root ownership/mode—the
helper does not silently chmod operator files. Errors produce only fixed safe
reason codes, never database rows, prompts, credentials, or raw exceptions.

ProtectHome, ProtectSystem, NoNewPrivileges and the coordinator capability bounds
are retained. The coordinator carries ambient CAP_SETUID, already present in its
unchanged bounding set: target A/B qualification showed explicit systemd root
identity setup otherwise retained only DAC_OVERRIDE/SETGID effective capabilities,
causing UID-drop Popen to fail with EPERM. This is a coordinator launch requirement,
not permission for research workers to retain root capabilities. Target worker
receipts must show NoNewPrivs=1 and zero permitted/effective/ambient capabilities
after exec. Inheritable bits alone are not required to be zero; with no permitted
capabilities and NoNewPrivs, they do not attest a capability gain. Actual descendant
fencing and target confinement still require separate observations.
Literal private listener/proxy admission and native read-only bind
mounts remain operator overlay responsibilities. Actual root/cgroup/systemd
acceptance must be performed on the target; offline tests use fake cgroup files
and test the real Store only in a temporary private SQLite directory:

```text
python3 -m unittest bridge.tests.test_host_lifecycle -v
```
