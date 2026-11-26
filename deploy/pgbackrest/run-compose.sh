#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ $# == 2 && -f "$1" ]] || { echo "Usage: $0 PRIVATE_RELEASE_ENV config|deploy|rollback" >&2; exit 2; }
action=$2
app=/srv/kernscale/apps/kernscale-trading-hub
project=kernscale-trading-hub-state
datadir=/var/lib/postgresql/data
base=postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac
derivative='^ghcr\.io/kernscale/trading/postgres:17-pgbackrest@sha256:[a-f0-9]{64}$'

fail() { echo "$*" >&2; exit 1; }
case "$action" in
  config) ;;
  deploy|rollback)
    [[ "$EUID" == 0 && -n "${TMUX:-}" && $(hostname -s) == kernscale2-ops &&
       "$1" == "$app/release.env" ]] || fail 'Use the canonical Trading path in the Ops2 deployment tmux session.'
    capture_lock=/var/lib/kernscale-trading-backup/attempts/capture.lock
    [[ -f "$capture_lock" && $(realpath "$capture_lock") == "$capture_lock" ]] ||
      fail 'The existing Trading paired capture lock is required.'
    exec {paired_lock}>>"$capture_lock"
    flock --wait 3600 "$paired_lock"
    exec {native_lock}>>/run/lock/kernscale-trading-pgbackrest.lock
    flock --wait 3600 "$native_lock"
    for private in "$app/release.env" "$app/postgres.env"; do
      [[ -f "$private" && $(realpath "$private") == "$private" &&
         $(stat -c '%u:%a' "$private") == 0:600 ]] ||
        fail 'Trading requires its existing private release and database env files.'
    done
    ;;
  *) exit 2 ;;
esac

docker=(env -i HOME="$HOME" PATH="$PATH" docker --host unix:///var/run/docker.sock)
compose=("${docker[@]}" compose --project-name "$project" --project-directory "$directory/.."
  --env-file "$1" --file "$directory/../state.compose.yaml")
[[ "$action" == rollback ]] || compose+=(--file "$directory/compose.overlay.yaml")
model=$("${compose[@]}" config --no-env-resolution --format json postgres 2>/dev/null) ||
  fail 'Could not validate the Trading release Compose model.'
image=$(jq -er --arg project "$project" --arg data "$datadir" '
  select(.name==$project and (.services|keys)==["postgres"] and
         .volumes.postgres.name=="kernscale-trading-hub-postgres" and .volumes.postgres.external==true and
         .networks.data.name=="kernscale-trading-hub-data" and .networks.data.external==true and
         (.services.postgres.ports // [])==[] and
         (.services.postgres.volumes | map(select(.target==$data)) |
           length==1 and .[0].type=="volume" and .[0].source=="postgres" and (.[0].read_only//false)==false)) |
  .services.postgres.image
' <<<"$model")
if [[ "$action" == rollback ]]; then
  [[ "$image" == "$base" ]] || fail 'Expected the pinned Trading rollback image.'
else
  [[ "$image" =~ $derivative ]] || fail 'Expected the approved immutable Trading PG17 derivative.'
fi
[[ "$action" != config ]] || exit 0

jq -e --arg env "$app/postgres.env" '
  .services.postgres.env_file | length==1 and .[0].path==$env
' <<<"$model" >/dev/null || fail 'The rendered Trading database env path differs.'

select_running() {
  "${docker[@]}" ps --no-trunc --filter "label=com.docker.compose.project=$project" \
    --filter label=com.docker.compose.service=postgres --filter status=running --format '{{.ID}}'
}
id=$(select_running)
[[ "$id" =~ ^[a-f0-9]{64}$ ]] || fail 'Expected exactly one running Trading PostgreSQL workload.'
format='{
  "id":{{json .Id}},"image":{{json .Config.Image}},"imageId":{{json .Image}},
  "project":{{json (index .Config.Labels "com.docker.compose.project")}},
  "service":{{json (index .Config.Labels "com.docker.compose.service")}},
  "state":{{json .State}},"mounts":{{json .Mounts}},
  "ports":{{json .HostConfig.PortBindings}},"networks":{{json .NetworkSettings.Networks}}
}'
current=$("${docker[@]}" inspect --format "$format" "$id")
live=$(jq -er --arg id "$id" --arg project "$project" --arg data "$datadir" '
  select(.id==$id and .project==$project and .service=="postgres" and
    .state.Running and .state.Health.Status=="healthy" and (.ports//{})=={} and
    (.networks | has("kernscale-trading-hub-data")) and
    (.mounts | map(select(.Destination==$data)) |
      length==1 and .[0].Type=="volume" and .[0].Name=="kernscale-trading-hub-postgres" and .[0].RW)) |
  .image
' <<<"$current") || fail 'The existing Trading database is outside its healthy private state contract.'
[[ "$live" == "$base" || "$live" =~ $derivative ]] || fail 'Unexpected existing Trading image.'
resolved_live_id=$("${docker[@]}" image inspect --format '{{.Id}}' "$live")
[[ "$resolved_live_id" == "$(jq -er .imageId <<<"$current")" ]] || fail 'The existing Trading image ID does not match its pin.'

read_cluster() {
  "${docker[@]}" exec --user postgres "$1" psql -X -qAt -w -v ON_ERROR_STOP=1
  -U kernscale_trading_admin -d trading -c "
  SELECT current_setting('server_version_num'), current_database(), current_user,
         current_setting('data_directory'), pg_is_in_recovery(),
         (SELECT string_agg(extname || '=' || extversion, ',' ORDER BY extname) FROM pg_extension),
         (SELECT system_identifier::text FROM pg_control_system())"
}
identity=$(read_cluster "$id")
expected="170012|trading|kernscale_trading_admin|$datadir|f|plpgsql=1.0|"
[[ "$identity" == "$expected"* && "${identity#"$expected"}" =~ ^[0-9]{16,20}$ ]] ||
  fail 'Trading PostgreSQL did not prove exact version, role, data directory, extension and primary identity.'
system_id=${identity#"$expected"}

# Resolve only the validated file. Keep all values in memory and out of diagnostics.
apply=("${docker[@]}" compose --project-name "$project" --project-directory "$directory/.."
  --env-file /dev/null --file -)
resolved=$(printf '%s\n' "$model" | "${apply[@]}" config --format json postgres 2>/dev/null) ||
  fail 'Could not resolve the canonical Trading database configuration.'
if ! {
  printf '%s\n' "$resolved"
  "${docker[@]}" image inspect --format '{{json .Config.Env}}' "$image"
  "${docker[@]}" inspect --format '{{json .Config.Env}}' "$id"
} | jq -e -s --arg data "$datadir" '
  def vars:
    map(capture("^(?<key>[^=]+)=(?<value>.*)$"; "s")) | from_entries;
  ((.[1]|vars) + (.[0].services.postgres.environment//{} | map_values(gsub("\\$\\$"; "$")))) as $proposed |
  (.[2]|vars) as $live |
  ($proposed|del(.TRADING_EXPECTED_SYSTEM_ID))==($live|del(.TRADING_EXPECTED_SYSTEM_ID)) and
  $proposed.PGDATA==$data and $proposed.POSTGRES_DB=="trading" and
  $proposed.POSTGRES_USER=="kernscale_trading_admin"
' >/dev/null 2>&1; then
  fail 'The effective PostgreSQL environment differs from the verified existing cluster.'
fi

guard=$directory/existing-cluster.sh
[[ -f "$guard" && -x "$guard" && $(realpath "$guard") == "$guard" ]] || fail 'The reviewed existing-cluster startup guard is required.'
frozen=$(printf '%s\n' "$resolved" | jq -c --arg id "$system_id" --arg guard "$guard" '
  del(.services.postgres.env_file) |
  .services.postgres.entrypoint=["/trading-existing-cluster.sh"] |
  .services.postgres.command=(.services.postgres.command // ["postgres"]) |
  .services.postgres.environment.TRADING_EXPECTED_SYSTEM_ID=$id |
  .services.postgres.volumes += [{type:"bind",source:$guard,target:"/trading-existing-cluster.sh",read_only:true,bind:{create_host_path:false}}]
')
reparsed=$(printf '%s\n' "$frozen" | "${apply[@]}" config --format json postgres 2>/dev/null) ||
  fail 'The frozen Trading model failed Compose validation.'
printf '%s\n%s\n' "$frozen" "$reparsed" | jq -e -s '.[0]==.[1]' >/dev/null ||
  fail 'The frozen Trading model did not round-trip unchanged.'
[[ "$(select_running)" == "$id" &&
   $("${docker[@]}" inspect --format '{{.State.Running}}/{{.State.Health.Status}}' "$id") == true/healthy &&
   $(read_cluster "$id") == "$identity" ]] || fail 'Trading changed after the preflight. No deployment was started.'
hash=$(printf '%s\n' "$frozen" | jq -cS 'del(.services.postgres.environment)' | sha256sum)
printf 'Validated redacted Trading PostgreSQL Compose SHA256=%s\n' "${hash%% *}"
printf '%s\n' "$frozen" | "${apply[@]}" up -d --no-deps --wait --wait-timeout 180 postgres
new_id=$(select_running)
[[ "$new_id" =~ ^[a-f0-9]{64}$ &&
   $("${docker[@]}" inspect --format '{{.Config.Image}}' "$new_id") == "$image" &&
   $(read_cluster "$new_id") == "$identity" ]] || fail 'Trading did not prove the same PostgreSQL cluster on the selected image.'
printf 'Trading PostgreSQL verified on the selected image with the original cluster identity.\n'
