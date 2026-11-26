#!/usr/bin/env bash
set -euo pipefail
[[ "$EUID" == 0 && "${CI:-}" == true && $(uname -s) == Linux ]] || {
  echo 'Run this fixture only as root on the disposable Linux CI runner.' >&2
  exit 2
}
source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
app=/srv/kernscale/apps/kernscale-trading-hub
capture_dir=/var/lib/kernscale-trading-backup
archive_lock=/run/lock/kernscale-trading-pgbackrest.lock
[[ ! -e "$app" && ! -e "$capture_dir" && ! -e "$archive_lock" ]]
work=$(mktemp -d)
cleanup() { rm -rf -- "$work" "$app" "$capture_dir"; rm -f -- "$archive_lock"; }
trap cleanup EXIT
umask 077
mkdir -p "$app/backup-files" "$capture_dir/attempts"
touch "$capture_dir/attempts/capture.lock"
command -v docker >"$work/real-docker"
base=postgres:17@sha256:0d938e975177bef9f1f61f3f9dbcf2e0aa0a139568f66d3415ed8892eb97f6ac
candidate=ghcr.io/kernscale/trading/postgres:17-pgbackrest@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
printf '%s\n' "$base" >"$work/base"
printf '%s\n' "$candidate" >"$work/candidate"
cat >"$work/hostname" <<'EOF'
#!/usr/bin/env bash
[[ "$*" == -s ]]
echo kernscale2-ops
EOF
cat >"$work/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
work=$(dirname -- "$0")
[[ "$1 $2" == '--host unix:///var/run/docker.sock' ]]
real=$(<"$work/real-docker")
mode=$(<"$work/mode")
base=$(<"$work/base")
imgid=sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
container=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
data=/var/lib/postgresql/data
system_id=7712345678901234567
for lock in /var/lib/kernscale-trading-backup/attempts/capture.lock /run/lock/kernscale-trading-pgbackrest.lock; do
  if flock --nonblock "$lock" true; then echo 'Docker ran outside a required lock.' >&2; exit 99; fi
done
case "$3" in
  compose)
    case " $* " in
      *' config '*) exec "$real" "$@" ;;
      *' up -d --no-deps --wait --wait-timeout 180 postgres ')
        touch "$work/up"
        "$real" "${@:1:11}" config --format json postgres |
          jq -e --arg system "$system_id" --arg image "$(<"$work/selected")" '
            .services.postgres |
            .env_file==null and .image==$image and
            .entrypoint==["/trading-existing-cluster.sh"] and .command[0]=="postgres" and
            .environment.PGDATA=="/var/lib/postgresql/data" and
            .environment.POSTGRES_DB=="trading" and
            .environment.POSTGRES_USER=="kernscale_trading_admin" and
            .environment.POSTGRES_PASSWORD=="fixture-$${case_literal}" and
            .environment.TRADING_EXPECTED_SYSTEM_ID==$system and
            (.volumes | any(.target=="/trading-existing-cluster.sh" and .read_only))
          ' >/dev/null
        ;;
      *) exit 98 ;;
    esac
    ;;
  ps)
    [[ "$*" == *'--filter status=running'* && "$*" != *--all* ]]
    if [[ "$mode" != stopped ]]; then echo "$container"; fi
    ;;
  image)
    if [[ "$6" == '{{.Id}}' ]]; then
      if [[ "$mode" == wrong-image-id ]]; then echo sha256:wrong; else echo "$imgid"; fi
    elif [[ "$6" == '{{json .Config.Env}}' ]]; then
      jq -cn --arg d "$data" '["PGDATA="+$d]'
    else exit 98; fi
    ;;
  inspect)
    case "$5" in
      '{{json .Config.Env}}')
        jq -cn --arg d "$data" --arg secret 'fixture-${case_literal}' '[
          "PGDATA="+$d,"POSTGRES_DB=trading","POSTGRES_USER=kernscale_trading_admin","POSTGRES_PASSWORD="+$secret]'
        ;;
      '{{.State.Running}}/{{.State.Health.Status}}') echo true/healthy ;;
      '{{.Config.Image}}') cat "$work/selected" ;;
      *)
        state=healthy image=$base
        [[ "$mode" != unhealthy ]] || state=unhealthy
        [[ "$mode" != wrong-image ]] || image=postgres:other
        jq -cn --arg id "$container" --arg image "$image" --arg imgid "$imgid" --arg state "$state" --arg d "$data"           '{id:$id,image:$image,imageId:$imgid,project:"kernscale-trading-hub-state",service:"postgres",
            state:{Running:true,Health:{Status:$state}},mounts:[{Destination:$d,Name:"kernscale-trading-hub-postgres",Type:"volume",RW:true}],
            ports:{},networks:{"kernscale-trading-hub-data":{}}}'
        ;;
    esac
    ;;
  exec)
    shift 3
    [[ $# == 15 && "$*" == "--user postgres $container psql -X -qAt -w -v ON_ERROR_STOP=1 -U kernscale_trading_admin -d trading -c "* &&
       "${@: -1}" == *"FROM pg_control_system()"* ]] || exit 98
    version=170012 recovery=f extension=plpgsql=1.0
    case "$mode" in
      wrong-version) version=170011 ;;
      wrong-data-directory) data=/different ;;
      wrong-extension) extension=plpgsql=1.1 ;;
      recovery) recovery=t ;;
      missing-identity) system_id='' ;;
      changes-during-preflight)
        if [[ -f "$work/read" ]]; then system_id=7799999999999999999; else touch "$work/read"; fi
        ;;
      freeze)
        if [[ -f "$work/read" && ! -f "$work/up" ]]; then
          echo 'POSTGRES_PASSWORD=changed-after-validation' >/srv/kernscale/apps/kernscale-trading-hub/postgres.env
        else touch "$work/read"; fi
        ;;
    esac
    printf '%s|trading|kernscale_trading_admin|%s|%s|%s|%s\n' "$version" "$data" "$recovery" "$extension" "$system_id"
    ;;
  *) exit 98 ;;
esac
EOF
chmod +x "$work/hostname" "$work/docker"
for action in deploy rollback; do
  selected=$candidate
  [[ "$action" != rollback ]] || selected=$base
  printf '%s\n' "$selected" >"$work/selected"
  cat >"$app/release.env" <<EOF
POSTGRES_IMAGE=$selected
POSTGRES_ENV_FILE=$app/postgres.env
REDIS_IMAGE=redis:7
REDIS_CONFIG_FILE=$app/redis.conf
TRADING_PGBACKREST_SECRETS=$app/backup-files
EOF
  for mode in wrong-pgdata wrong-db wrong-user wrong-secret startup-env stopped unhealthy wrong-image wrong-image-id wrong-version wrong-data-directory wrong-extension recovery missing-identity changes-during-preflight freeze; do
    rm -f "$work/up" "$work/read"
    printf '%s\n' "$mode" >"$work/mode"
    cat >"$app/postgres.env" <<'EOF'
PGDATA=/var/lib/postgresql/data
POSTGRES_DB=trading
POSTGRES_USER=kernscale_trading_admin
POSTGRES_PASSWORD='fixture-${case_literal}'
EOF
    case "$mode" in
      wrong-pgdata) sed -i 's#^PGDATA=.*#PGDATA=/var/lib/postgresql/data/alternate#' "$app/postgres.env" ;;
      wrong-db) sed -i 's/^POSTGRES_DB=.*/POSTGRES_DB=other/' "$app/postgres.env" ;;
      wrong-user) sed -i 's/^POSTGRES_USER=.*/POSTGRES_USER=other/' "$app/postgres.env" ;;
      wrong-secret) sed -i 's/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=different/' "$app/postgres.env" ;;
      startup-env) echo 'BASH_ENV=/unexpected' >>"$app/postgres.env" ;;
    esac
    status=0
    PATH="$work:$PATH" TMUX=fixture bash "$source_dir/run-compose.sh" "$app/release.env" "$action" >"$work/result" 2>&1 || status=$?
    if [[ "$mode" == freeze ]]; then
      if [[ "$status" != 0 || ! -f "$work/up" ]]; then cat "$work/result" >&2; exit 1; fi
      grep -F 'Trading PostgreSQL verified on the selected image' "$work/result" >/dev/null
    elif [[ "$status" != 1 || -e "$work/up" ]]; then
      printf '%s unexpectedly admitted %s (exit %s)\n' "$action" "$mode" "$status" >&2
      cat "$work/result" >&2
      exit 1
    fi
    case "$mode" in
      wrong-pgdata|wrong-db|wrong-user|wrong-secret|startup-env)
        expected='The effective PostgreSQL environment differs' ;;
      stopped) expected='Expected exactly one running Trading PostgreSQL workload' ;;
      unhealthy) expected='outside its healthy private state contract' ;;
      wrong-image) expected='Unexpected existing Trading image' ;;
      wrong-image-id) expected='does not match its pin' ;;
      wrong-version|wrong-data-directory|wrong-extension|recovery|missing-identity)
        expected='did not prove exact version' ;;
      changes-during-preflight) expected='Trading changed after the preflight' ;;
      freeze) expected='Trading PostgreSQL verified on the selected image' ;;
    esac
    if ! grep -qF "$expected" "$work/result"; then
      printf 'Wrong denial or acceptance for %s %s\n' "$action" "$mode" >&2
      cat "$work/result" >&2
      exit 1
    fi
    if grep -qF 'case_literal' "$work/result"; then echo 'A fixture secret reached the activation output.' >&2; exit 1; fi
    printf '%s %s verified\n' "$action" "$mode"
  done
done
