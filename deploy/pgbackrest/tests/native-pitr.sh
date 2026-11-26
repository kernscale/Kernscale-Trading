#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 1 ]] || { echo "Usage: $0 CANDIDATE_IMAGE" >&2; exit 2; }
image=$1
recovery=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
version=17.12 data_path=/var/lib/postgresql/data
stanza=trading-hub credentials=/run/trading-pgbackrest
config=$recovery/pgbackrest.conf
docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
work=$(mktemp -d)
namespace=trading-pr-${work##*.}
namespace=${namespace,,}
sftp=$namespace-sftp
container=
writer_host=writer.trading-ci.invalid
reader_host=reader.trading-ci.invalid
repo=/home/pgbackrest/$stanza
target=trading-fixture-before-after

cleanup() {
  status=$?
  trap - EXIT
  if [[ -n "$container" ]]; then
    if [[ "$status" != 0 ]]; then "${docker[@]}" logs --tail 25 "$container" >&2 || true; fi
    "${docker[@]}" rm -fv "$container" >/dev/null 2>&1 || true
  fi
  if [[ "$status" != 0 ]]; then "${docker[@]}" logs --tail 25 "$sftp" >&2 || true; fi
  "${docker[@]}" rm -fv "$sftp" >/dev/null 2>&1 || true
  "${docker[@]}" network rm "$namespace" >/dev/null 2>&1 || true
  "${docker[@]}" image rm "$sftp:local" >/dev/null 2>&1 || true
  sudo rm -rf -- "$work"
  exit "$status"
}
trap cleanup EXIT

mkdir "$work"/{sftp,writer,reader}
for key in host writer/identity reader/identity; do
  ssh-keygen -q -t ed25519 -N '' -f "$work/$key"
done
for host in "$writer_host" "$reader_host"; do
  printf '[%s]:23 %s\n' "$host" "$(cut -d ' ' -f 1-2 "$work/host.pub")"
done >"$work/known_hosts"
cp "$work/writer/identity.pub" "$work/sftp/fixture-writer"
cp "$work/reader/identity.pub" "$work/sftp/fixture-reader"
for account in writer reader; do
  mv "$work/$account/identity" "$work/$account/storagebox.key"
  cp "$work/known_hosts" "$work/$account/storagebox.known_hosts"
  printf '[global]\nrepo1-cipher-pass=trading-disposable-sftp-repository-key\n' >"$work/$account/10-cipher.conf"
  chmod 700 "$work/$account"
  chmod 600 "$work/$account/"{storagebox.key,storagebox.known_hosts,10-cipher.conf}
  sudo chown -R 999:999 "$work/$account"
done

cat >"$work/sftp/sshd_config" <<'SSHD'
Port 23
HostKey /fixture-hostkey
AuthorizedKeysFile /etc/ssh/fixture/%u
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
DisableForwarding yes
Subsystem sftp internal-sftp
Match User fixture-reader
  ForceCommand internal-sftp -R
Match User fixture-writer
  ForceCommand internal-sftp
SSHD
cat >"$work/sftp/Dockerfile" <<'DOCKER'
FROM debian:bookworm-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e
ARG STANZA
RUN apt-get update && apt-get install -y --no-install-recommends openssh-server && rm -rf /var/lib/apt/lists/* \
    && groupadd fixture && useradd -g fixture -M fixture-writer && useradd -g fixture -M fixture-reader \
    && passwd -d fixture-writer && passwd -d fixture-reader \
    && install -d -m 2750 -o fixture-writer -g fixture "/home/pgbackrest/$STANZA" \
    && mkdir -p /run/sshd /etc/ssh/fixture
COPY sshd_config /etc/ssh/sshd_config
COPY fixture-writer fixture-reader /etc/ssh/fixture/
ENTRYPOINT ["/usr/sbin/sshd", "-D", "-e"]
DOCKER
"${docker[@]}" build --build-arg "STANZA=$stanza" --tag "$sftp:local" "$work/sftp"
"${docker[@]}" network create --internal "$namespace" >/dev/null
"${docker[@]}" run -d --name "$sftp" --network "$namespace" \
  --network-alias "$writer_host" --network-alias "$reader_host" \
  --mount "type=bind,src=$work/host,dst=/fixture-hostkey,readonly" \
  --mount "type=bind,src=$work/reader,dst=/fixture-reader,readonly" "$sftp:local" >/dev/null

runtime=(--network "$namespace" --memory=768m --pids-limit=96 --security-opt no-new-privileges:true
  --tmpfs "$data_path:size=512m,uid=999,gid=999,mode=0700"
  --tmpfs /var/run/postgresql:uid=999,gid=999,mode=0770
  --tmpfs /run/pgbackrest:uid=999,gid=999,mode=0700
  --tmpfs /tmp:mode=1777
  --mount "type=bind,src=$config,dst=/etc/pgbackrest/pgbackrest.conf,readonly"
  --env "PGBACKREST_REPO1_PATH=$repo")
container=$("${docker[@]}" run -d "${runtime[@]}" --env POSTGRES_USER=kernscale_trading_admin --env POSTGRES_DB=trading --env POSTGRES_HOST_AUTH_METHOD=trust \
  --env "PGBACKREST_REPO1_SFTP_HOST=$writer_host" --env PGBACKREST_REPO1_SFTP_HOST_USER=fixture-writer \
  --mount "type=bind,src=$work/writer,dst=$credentials,readonly" "$image" \
  postgres -c archive_mode=on -c "archive_command=pgbackrest --stanza=$stanza --config-include-path=$credentials archive-push %p")
[[ "$container" =~ ^[a-f0-9]{64}$ && -z $("${docker[@]}" port "$container") ]]
run=("${docker[@]}" exec --user postgres "$container")
ready=false
for attempt in {1..60}; do
  if "${run[@]}" pg_isready -h 127.0.0.1 -U kernscale_trading_admin >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 1
done
$ready || exit 1
[[ $("${run[@]}" postgres --version) == "postgres (PostgreSQL) $version"* ]]
[[ $("${run[@]}" pgbackrest version) == "pgBackRest 2.60.1" ]]
sql=("${run[@]}" psql -X -qAt -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading)
"${docker[@]}" exec -i --user postgres "$container" psql -X -q -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading <<'SQL'
CREATE TABLE users(id bigint PRIMARY KEY);
CREATE TABLE research_sessions(id uuid PRIMARY KEY, user_id bigint REFERENCES users(id));
CREATE TABLE research_runs(id uuid PRIMARY KEY, research_session_id uuid NOT NULL REFERENCES research_sessions(id), user_id bigint REFERENCES users(id));
CREATE TABLE recovery_fixture(name text PRIMARY KEY);
CREATE ROLE recovery_fixture_reader NOLOGIN NOBYPASSRLS;
CREATE TABLE recovery_fixture_scope(workspace_id text PRIMARY KEY);
INSERT INTO recovery_fixture_scope VALUES ('workspace-allowed'), ('workspace-denied');
ALTER TABLE recovery_fixture_scope ENABLE ROW LEVEL SECURITY;
CREATE POLICY fixture_workspace ON recovery_fixture_scope
  USING (workspace_id = current_setting('fixture.workspace', true));
GRANT SELECT ON recovery_fixture_scope TO recovery_fixture_reader;
SQL

backrest=("${run[@]}" pgbackrest "--stanza=$stanza" "--config-include-path=$credentials")
backup=("${backrest[@]}" --pg1-user=kernscale_trading_admin)
"${backup[@]}" stanza-create
"${backup[@]}" check
"${backup[@]}" --type=full --archive-check --archive-copy backup
label=$("${backrest[@]}" --log-level-console=off --output=json info | jq -er '.[0].backup[-1].label')
"${sql[@]}" -c "INSERT INTO recovery_fixture VALUES ('before')"
"${sql[@]}" -c "SELECT pg_create_restore_point('$target')"
"${sql[@]}" -c "INSERT INTO recovery_fixture VALUES ('after')"
"${backup[@]}" check

if printf 'put /etc/hosts %s/denied-write\n' "$repo" | "${docker[@]}" exec -i "$sftp" sftp \
  -P 23 -b - -i /fixture-reader/storagebox.key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile=/fixture-reader/storagebox.known_hosts "fixture-reader@$reader_host" >"$work/denial" 2>&1; then
  echo "Unexpected SFTP write permission for the recovery identity" >&2; exit 1
fi
grep -iF 'permission denied' "$work/denial"

restorer=("${docker[@]}" run --rm "${runtime[@]}" --user postgres --cap-drop ALL --read-only
  --env "PGBACKREST_REPO1_SFTP_HOST=$reader_host" --env PGBACKREST_REPO1_SFTP_HOST_USER=fixture-reader
  --mount "type=bind,src=$work/reader,dst=$credentials,readonly")
"${restorer[@]}" --mount "type=bind,src=$recovery/verify-restored.sh,dst=/opt/verify-restored.sh,readonly" \
  --entrypoint bash "$image" /opt/verify-restored.sh "$label" "$target" |
  jq -e --arg label "$label" --arg target "$target" \
    '.backup==$label and .target==$target and .tradingSchemaReadable==true'
"${restorer[@]}" -i --entrypoint bash "$image" -se -- "$data_path" "$credentials" "$stanza" "$label" "$target" <<'RESTORE'
  set -o pipefail
  [[ "$PGDATA" == "$1" ]]
  mkdir -p "$PGDATA"
  pgbackrest "--config-include-path=$2" "--stanza=$3" "--set=$4" --type=name "--target=$5" --target-action=promote restore >&2
  trap "pg_ctl -D \"$PGDATA\" -m immediate stop >/dev/null 2>&1 || true" EXIT
  pg_ctl -D "$PGDATA" -l /tmp/postgres-restore.log -o "-c listen_addresses=\"\" -c archive_mode=off" -w start >&2
  sql=(psql -X -qAt -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading)
  for attempt in {1..60}; do
    state=$("${sql[@]}" -c "SELECT pg_is_in_recovery()" || true)
    [[ "$state" == f ]] && break
    sleep 1
  done
  [[ "$state" == f ]]
  [[ $("${sql[@]}" -c "SELECT string_agg(name, ',' ORDER BY name) FROM recovery_fixture") == before ]]
  [[ $("${sql[@]}" -c "SELECT string_agg(extname || '=' || extversion, ',' ORDER BY extname) FROM pg_extension") == plpgsql=1.0 ]]
  allowed=$("${sql[@]}" <<'SQL'
SET ROLE recovery_fixture_reader;
SET fixture.workspace = 'workspace-allowed';
SELECT string_agg(workspace_id, ',' ORDER BY workspace_id) FROM recovery_fixture_scope;
SQL
  )
  [[ "$allowed" == workspace-allowed ]]
  "${sql[@]}" <<'SQL'
DO $fixture$
BEGIN
  BEGIN
    INSERT INTO research_runs VALUES ('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', NULL);
    RAISE EXCEPTION 'restored Trading FK did not reject an orphan';
  EXCEPTION WHEN foreign_key_violation THEN
    NULL;
  END;
END
$fixture$;
SQL
RESTORE
echo "Trading PostgreSQL $version encrypted SFTP, read-only denial, native PITR, plpgsql, fixture RLS and Trading FK passed ($label, before=1, after=0)"
