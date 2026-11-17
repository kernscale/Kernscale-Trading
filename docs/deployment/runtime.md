# Trading Hub runtime package

The package prepares one Laravel 13 application on Ops VM100. It has not been
built, published, installed or admitted to ingress. Existing VM101 deployment
wrappers cannot install it. Production changes require the concrete release
acknowledgements.

`deploy/Dockerfile` reuses the Dashboard's pinned PHP 8.4 FrankenPHP, Node and
Composer base identities. PHP extensions include PostgreSQL, Redis and Horizon's
process support. The frontend stage has PHP/vendor available for Wayfinder;
`npm ci --ignore-scripts && npm run build` runs only during an authorized image
build. The runtime is nonroot, serves HTTP on container port 8000 and contains no
Node runtime. Explicit source copies omit `.env`, local storage and cached
bootstrap configuration. No startup command generates keys, creates users or
runs migrations.

`deploy/compose.yaml` creates a replaceable blue/green app slot using the same
immutable image for HTTP, Horizon and `schedule:work`. Durable storage is an
external volume. `deploy/state.compose.yaml` owns a separate stable state project
for PostgreSQL 17 and Redis 7, both on the private application data network with
external volumes and no published ports. Redis configuration is a protected
read-only file; configure persistence and authenticated access there. Redis
queue transport must be reconciled against PostgreSQL dispatch intent after an
outage.

Use `deploy/release.env.example` only for nonsecret Compose interpolation. `UNSET`
values deliberately do not name real images. Select exact digests and source
commit through the protected application release chain. `deploy/validate.py`
resolves both Compose documents without a Docker daemon and rejects mutable
images, wrong state major versions, ports, worker ingress and nonexternal state.
Do not print `docker compose config` output: it resolves protected env files.
The validator suppresses that output.

The separate protected runtime environment must set `APP_KEY`, `APP_URL`,
`DB_CONNECTION=pgsql`, `DB_HOST=postgres`, PostgreSQL database/user/password,
`QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=redis`, authenticated
Redis settings, database-backed sessions/cache, secure cookies, mail delivery,
private bridge URL/token, and the reviewed trusted-proxy/origin settings. Keep the
same application key for retained encrypted state. The protected PostgreSQL env
file contains `POSTGRES_DB`, `POSTGRES_USER` and `POSTGRES_PASSWORD`. Neither file
belongs in Git, image layers, command arguments or shared logs.

Before serving, the host admission must create and verify exactly the app's
external ingress, private data and private application egress networks, plus
storage/PostgreSQL/Redis volumes and their ownership. No existing Ops monitoring
network is reused. Traefik labels are proposed source only; they do not prove an
Ops tunnel or route. Only HTTP joins ingress. Queue and scheduler join data and
application egress; PostgreSQL and Redis join data only. Resource reservations
remain a measured live-capacity decision.

`/ready` must fail when the application database is unhealthy and return the
exact `KERNSCALE_RELEASE_IMAGE` and `KERNSCALE_RELEASE_COMMIT` supplied by Compose.
An unavailable private bridge is a disclosed degraded backend, preserving saved
views. Verify liveness separately. The package's HTTP healthcheck probes `/ready`;
Horizon uses its supported `horizon:status`. Scheduler ownership and process
health require release-controller evidence.

Workers are disabled unless the `workers` profile is explicitly selected. This
profile is a promotion gate, not a distributed lock: the restricted release
controller must fence and drain the previous slot before starting the selected
slot's Horizon and scheduler, reconcile uncertain dispatch and prohibit scaling
these services. Inspect Horizon supervisor concurrency/timeouts against actual
hardware and job contracts before release; vendor defaults are not accepted
capacity evidence. Do not enable both slots' worker profiles. Native Agents2
research and paper schedulers remain untouched.

Migration review, application-profile manifest/release validation, OCI labels,
SBOM/provenance, backward-compatible schema receipt, first-release non-serving
fallback, immutable private/public identity and rollback remain release gates.
The Operations `backup/server2/trading-hub/` package owns native capture,
encrypted off-host publication and real restore acceptance. Archive readback is
not an application restore; no one-hour RPO or four-hour RTO is yet accepted.

Focused offline validation:

```sh
python3 deploy/tests/test_runtime.py
```

The initial package checks were offline. Subsequent private source/image
publication and immutable image-config readback are recorded in
`.orchestration/reports/2026-11-17-private-image-candidate.md`. Container boot,
target migration, host admission, route cutover and real recovery remain
unaccepted; image publication does not establish those outcomes.

## Read-only Ops observation

Observed at approximately `2026-11-16T17:01:30Z` through the existing
`kernscale2-ops` SSH alias with BatchMode, an eight-second connection timeout
and strict known-host checking. Network sandbox escalation permitted the read;
no remote files, services or routes changed.

The host reports `kernscale2-ops`, x86_64, two logical CPUs, 9,428 MiB RAM
(5,015 MiB available), and 1,199 MiB swap in use out of 2,966 MiB. `/` and `/srv`
share the same filesystem: 123,529,228 KiB total, 41,614,156 KiB available,
65% used. These are point-in-time measurements, not reservations. Existing
Tailscale listeners already include port 8000; this package publishes no host
ports. `/srv/kernscale/apps` and `/srv/kernscale/proxy/traefik` were absent;
`/usr/local/libexec/kernscale` exists.

The SSH account cannot access the Docker socket. One `sudo -n` inventory attempt
reported that interactive authentication is required. Docker server version,
running container identities, ingress networks, tunnel/Traefik existence,
restricted release allowlists and exact hypervisor VM100 identity therefore
remain unverified. No privileged retry or workaround was attempted. Source VM
mapping identifies this hostname as VM100; an authenticated hypervisor receipt
is still required. No VM101 inspection or changes occurred.

### Access-path and Agents2 followup

Read-only followup at approximately `2026-11-16T17:36:11Z` found configured SSH
aliases for Ops, Production, Agents2 and PVE, but no separate Ops admin/deploy
alias. Exposed MCP capabilities do not provide an authenticated Ops deployment
interface. The existing Server 2 restricted release policy explicitly names
`kernscale2-prod`, not Ops; it cannot admit this app. Operations' existing PVE
backup transport targets guest VM100 for native recovery. That source documents
backup ownership, not permission to repurpose it as an application deployment
bypass. No alternate privileged identity or guest-command workaround was tried.

The existing Agents2 alias returned hostname `kernscale2-agents`. Native product
checkouts `TradingAgents`, `Trading-CLI` and `Trading-R1` exist under
`/home/jonas/repositories/products`; the new `Kernscale-Trading` checkout does
not. `/home/jonas/.local/share/tradingagents-paper/paper.sqlite` is a regular
11,116,544-byte file with mode 600; its `research` directory has mode 700.
`/home/jonas/.config/tradingagents/paper.env` exists with mode 600; no configuration
or credential values were read. These are observed native recovery candidates,
not proof that an authenticated bridge is installed.

The native research timer was active/waiting, last triggered at
`2026-11-16T16:10:30Z`, with next trigger `2026-11-16T18:10:18Z`. The paper tick
timer was active/waiting, last triggered at `2026-11-16T17:34:42Z`. The existing
paper UI service was active/running. No timer, ledger, strategy or service was
changed; timer state does not prove successful model research or ledger marks.

The operator's next step is an authenticated Ops administrator session or an
explicitly admitted read-only helper that can inspect Docker/Traefik/tunnel
identities, networks and the literal restricted-release allowlists. Keep that
inspection read-only. After reviewing the concrete installer/release and granting
release approval, the operator can install an app-scoped restricted interface;
sharing a sudo password or granting the ordinary agent account broad Docker/root
access is unnecessary. Until that owner step, Ops privileged admission is blocked.


## HTTPS proxy boundary

Set `APP_URL` to the actual HTTPS origin and `SESSION_SECURE_COOKIE=true`. Production readiness refuses a plain HTTP origin or insecure session cookies. Set `TRUSTED_PROXIES` to the exact admitted ingress peer addresses/CIDRs observed on Ops; the application default trusts only loopback. Do not use `*`. The first-party proxy middleware has a focused test proving an outside address cannot supply `X-Forwarded-Proto`. WebAuthn origin/RP enrollment must still be verified in the real browser at cutover.
