#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ $# == 2 && -f "$1" ]] || { echo "Usage: $0 PRIVATE_RELEASE_ENV config|deploy|rollback" >&2; exit 2; }
action=$2
app=/srv/kernscale/apps/kernscale-trading-hub
project=kernscale-trading-hub-state
base=postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac
derivative='^ghcr\.io/kernscale/trading/postgres:17-pgbackrest@sha256:[a-f0-9]{64}$'
case "$action" in
  config) ;;
  deploy|rollback)
    [[ "$EUID" == 0 && -n "${TMUX:-}" && $(hostname -s) == kernscale2-ops &&
       "$1" == "$app/release.env" ]] || {
      echo 'Use the canonical Trading path in the Ops2 deployment tmux session.' >&2; exit 2;
    }
    for private in "$app/release.env" "$app/postgres.env"; do
      [[ -f "$private" && $(realpath "$private") == "$private" &&
         $(stat -c '%u:%a' "$private") == 0:600 ]] || {
        echo 'Trading requires its existing private release and database env files.' >&2; exit 2;
      }
    done
    ;;
  *) exit 2 ;;
esac

docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
compose=("${docker[@]}" compose --project-name "$project" --project-directory "$directory/.."
  --env-file "$1" --file "$directory/../state.compose.yaml")
[[ "$action" == rollback ]] || compose+=(--file "$directory/compose.overlay.yaml")
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
plan=$scratch/postgres.json
"${compose[@]}" config --no-env-resolution --format json postgres >"$plan"
image=$(jq -er --arg project "$project" '
  select(.name==$project and (.services|keys)==["postgres"] and
         .volumes.postgres.name == "kernscale-trading-hub-postgres" and .volumes.postgres.external == true and
         .networks.data.name == "kernscale-trading-hub-data" and .networks.data.external == true and
         (.services.postgres.ports // []) == [] and
         (.services.postgres.volumes | any(.type=="volume" and .source=="postgres" and .target=="/var/lib/postgresql/data" and (.read_only//false)==false))) |
  .services.postgres.image
' "$plan")
if [[ "$action" == rollback ]]; then
  [[ "$image" == "$base" ]] || exit 2
else
  [[ "$image" =~ $derivative ]] || { echo 'Expected the approved immutable Trading PG17 derivative.' >&2; exit 2; }
fi
[[ "$action" != config ]] || exit 0

jq -e --arg env "$app/postgres.env" '
  (.services.postgres.env_file | length == 1 and .[0].path==$env) and
  (.services.postgres.volumes | map(select(.target=="/var/lib/postgresql/data")) | length == 1)
' "$plan" >/dev/null
flock --wait 3600 /var/lib/kernscale-trading-backup/attempts/capture.lock \
  flock --wait 3600 /run/lock/kernscale-trading-pgbackrest.lock bash -c '
  set -euo pipefail
  plan=$1 source=$2 project=$3 base=$4 derivative=$5
  docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
  id=$("${docker[@]}" ps --all --no-trunc --filter "label=com.docker.compose.project=$project" \
    --filter label=com.docker.compose.service=postgres --format "{{.ID}}")
  [[ "$id" =~ ^[a-f0-9]{64}$ ]] || {
    echo "Expected exactly one existing Trading PostgreSQL workload." >&2; exit 1;
  }
  format="{\"id\":{{json .Id}},\"image\":{{json .Config.Image}},\"mounts\":{{json .Mounts}},\"ports\":{{json .HostConfig.PortBindings}}}"
  current=$("${docker[@]}" inspect --format "$format" "$id")
  live=$(jq -er --arg id "$id" '\''
    select(.id==$id and (.ports // {})=={} and
      (.mounts | map(select(.Destination=="/var/lib/postgresql/data")) |
        length==1 and .[0].Type=="volume" and .[0].Name=="kernscale-trading-hub-postgres" and .[0].RW)) |
    .image
  '\'' <<<"$current")
  [[ "$live" == "$base" || "$live" =~ $derivative ]] || { echo "Unexpected existing Trading image." >&2; exit 1; }
  apply=("${docker[@]}" compose --project-name "$project" --project-directory "$source" --env-file /dev/null --file "$plan")
  "${apply[@]}" config --no-env-resolution --format json postgres >"${plan%/*}/checked.json"
  jq -e -s '\''.[0]==.[1]'\'' "$plan" "${plan%/*}/checked.json" >/dev/null
  hash=$(sha256sum <"$plan")
  printf "Validated Trading PostgreSQL Compose SHA256=%s\n" "${hash%% *}"
  exec "${apply[@]}" up -d --no-deps --wait --wait-timeout 180 postgres
' _ "$plan" "$directory/.." "$project" "$base" "$derivative"
