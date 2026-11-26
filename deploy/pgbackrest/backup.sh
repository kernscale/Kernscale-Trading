#!/usr/bin/env bash
set +x
set -euo pipefail
[[ $# == 1 && $(hostname -s) == kernscale2-ops ]] || {
  echo "Usage on kernscale2-ops: $0 stanza-create|check|info|full|diff" >&2; exit 2;
}
case "$1" in
  stanza-create|check) action=("$1") ;;
  info) action=(--log-level-console=off --output=json info) ;;
  full|diff) action=("--type=$1" --archive-check --archive-copy backup) ;;
  *) exit 2 ;;
esac
docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
container=$("${docker[@]}" ps --no-trunc --filter label=com.docker.compose.project=kernscale-trading-hub-state \
  --filter label=com.docker.compose.service=postgres --filter status=running --format '{{.ID}}')
[[ "$container" =~ ^[a-f0-9]{64}$ ]] || { echo 'Expected one Trading Hub PostgreSQL service.' >&2; exit 1; }
image=$("${docker[@]}" inspect --format '{{.Config.Image}}' "$container")
[[ "$image" =~ ^ghcr\.io/kernscale/trading/postgres:17-pgbackrest@sha256:[a-f0-9]{64}$ ]] || exit 2
if [[ "$1" != info ]]; then
  docker=(flock --wait 3600 /run/lock/kernscale-trading-pgbackrest.lock "${docker[@]}")
fi
exec "${docker[@]}" exec --user postgres "$container" bash -c '
  set +x
  set -euo pipefail
  [[ "$PGDATA" == /var/lib/postgresql/data ]]
  result=$(psql -X -qAt -w -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading \
    -c "SELECT NOT pg_is_in_recovery(), current_setting(\$\$archive_mode\$\$), current_setting(\$\$server_version_num\$\$)")
  [[ "$result" == "t|on|170012" ]] || { echo "Expected the archive-enabled PG17.12 primary." >&2; exit 1; }
  options=(--stanza=trading-hub --config-include-path=/run/trading-pgbackrest)
  [[ "${@: -1}" == info ]] || options+=(--pg1-user=kernscale_trading_admin)
  exec pgbackrest "${options[@]}" "$@"
' _ "${action[@]}"
