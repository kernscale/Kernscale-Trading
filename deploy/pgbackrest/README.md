# Trading Hub PostgreSQL native recovery

**Source prepared. Production recovery acceptance unknown.** The application
database stays on `kernscale2-ops` in
`kernscale-trading-hub-state/postgres`. The measured current Linux amd64 engine
is PostgreSQL 17.12 Debian `17.12-1.pgdg13+1`, with only plpgsql 1.0, at
`postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac`.
The derivative keeps that engine and adds pgBackRest 2.60.1. The release
reference must be
`ghcr.io/kernscale/trading/postgres:17-pgbackrest@sha256:<digest>` so the
existing Operations logical restore accepts the same PostgreSQL 17 family.

Use this as an additional PostgreSQL-only archive. Existing paired Operations
backup and recovery still own the common restore point across Ops2 application
data and Agents2 native ledger, reports, researcher state and configuration.
Keep Redis, protected application keys, existing logical archives, external
volume and private data network. Meaningful Trading recovery still requires
current job/authority reconciliation and one fenced writer across hosts.

## Before enabling archive

- Recheck the measured image, exact engine, extension, runtime volume and
  effective current configuration. Record an independently restorable current
  paired backup, current health and available disk/WAL/capacity measurements.
  Keep the existing one-hour and four-hour recovery objectives separate from
  the new native PostgreSQL checks.
- Provision only the approved existing writer's application prefix
  `/home/pgbackrest/trading-hub`, with strict SHA256 known-host validation and
  a unique client-side encryption key. Existing private directory files
  `storagebox.key`, `storagebox.known_hosts` and `10-cipher.conf` must be
  readable only by root and container UID 999. The cipher file contains a
  pgBackRest `[global]` `repo1-cipher-pass`. No credential values belong in
  source, Compose env files or receipts.
- Separate independent read-only Trading recovery is still required. The
  currently approved read-only Storage Box accounts belong to OpenTofu state
  and CMS. An approved Trading-scoped recovery path and explicit denied-write
  proof are prerequisites. No additional access is inferred.
- Agree the maximum recoverable age and independently enforce expiry under
  missing backups or a failed writer. This physical archive can retain data
  after product deletion. Automatic expiry begins disabled and the two full
  chains are a configurable chain target without an upper age guarantee.
  Establish separate WAL/disconnect/capacity and last-completed-backup alerts.
- Qualify PostgreSQL-only archive and recovery egress to the verified Storage
  Box endpoint. The overlay declares a separate bridge and does not install
  host policy. Keep existing application/network isolation and denied ingress.

## Activation and rollback

Every deployment and rollback uses the identified tmux window on
`kernscale2-ops`. Preserve and document the exact current canonical
`/srv/kernscale/apps/kernscale-trading-hub/release.env`, `postgres.env` and
the pinned canonical Compose. Validate a proposed env with
`./run-compose.sh PATH_TO_PROPOSED_ENV config` without emitting app values.
The env must retain existing canonical files and add only the reviewed
`POSTGRES_IMAGE` and `TRADING_PGBACKREST_SECRETS` references.

Coordinate a backup-free interval with the existing paired Operations
scheduler before installing the reviewed canonical release env. The old
paired input requires its `POSTGRES_IMAGE` to equal the live exact image.
Retain the original env before replacing it and restore it if activation
fails. Root privately links the existing capture lock and verifies no
parallel application changes. The wrapper acquires existing paired capture
and new archive locks throughout preflight and apply. It checks the exact
running, healthy project, volume and immutable image, then reads the cluster
identity from PostgreSQL 17.12 as the existing Trading database owner. It
requires the live primary, canonical data path and plpgsql 1.0. Compose must
resolve exactly the existing database environment before applying the frozen
plan from stdin. The receipt records a SHA256 of the redacted plan. The
startup guard verifies the actual PostgreSQL control-file identifier and
starts the existing cluster directly, without invoking initialization. The
wrapper verifies the same identity after apply.

Use `./run-compose.sh /srv/kernscale/apps/kernscale-trading-hub/release.env deploy`.
Check live DB, app authorization, all affected routes, unchanged non-PG
containers/named volume and the paired backup's accepted exact identity.
Restore old canonical env and use `rollback` in the same lineage if
acceptance fails. Rollback restores the pinned original 17.12 image on the
same named volume and retains new archive artifacts. Preserve the reviewed
source and hash because Docker records stdin as the Compose config source.
Both operations require a healthy running source. A failed startup that leaves
the service stopped requires the existing recovery procedure before the
wrapper can verify and accept a rollback.

After activation, run `backup.sh stanza-create`, `check`, `full` and `info`.
Record full label, WAL status, actual SFTP readback and freshness. Create a
unique `trading-...` restore point after the full backup using the existing
approved admin connection. Record the LSN and force/verify archival.

## Isolated restore

On Ops2 in the identified recovery tmux session:

```sh
./restore-check.sh IMAGE PRIVATE_RESTORE_DIR APPROVED_RO_USER RO_REPO_PATH BACKUP_LABEL TARGET
```

Recovery uses a distinct directory and isolated network, a read-only
credential mount, exact PG17.12 and local Unix sockets only. The verifier
checks plpgsql 1.0 and the Trading schema/FK and emits redacted JSON. It never
serves the restored cluster or points the app at it. Retain failure scratch
privately. Independently prove the complete paired logical restore as well.

Enable the provided serialized full/diff units after both native and paired
acceptance, capacity, retention and alert delivery have receipts. No unit
changes the intentionally paused full-VM backup timer. Production outcomes
still need their own exact image, tmux, availability and recovery evidence.
