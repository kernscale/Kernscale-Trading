#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
[[ $# == 2 && "$1" =~ ^[0-9]{8}-[0-9]{6}F(_[0-9]{8}-[0-9]{6}[DI])?$ && "$2" =~ ^trading-[a-z0-9-]{6,55}$ ]]
[[ "$PGDATA" == /var/lib/postgresql/data && ! -e "$PGDATA/PG_VERSION" ]]
label=$1 target=$2
start=$(date -u +%FT%TZ)
mkdir -p -- "$PGDATA"
backrest=(pgbackrest --stanza=trading-hub --config-include-path=/run/trading-pgbackrest)
"${backrest[@]}" --set="$label" info | grep -F "backup: $label" >&2
"${backrest[@]}" --set="$label" --type=name --target="$target" --target-action=promote restore >&2
printf 'local trading kernscale_trading_admin trust\n' >/tmp/recovery-pg_hba.conf
trap 'pg_ctl -D "$PGDATA" -m immediate stop >/dev/null 2>&1 || true' EXIT
pg_ctl -D "$PGDATA" -l /tmp/postgres-restore.log \
  -o "-c listen_addresses='' -c unix_socket_directories=/var/run/postgresql -c hba_file=/tmp/recovery-pg_hba.conf -c archive_mode=off -c primary_conninfo=''" \
  -w start >&2 || { tail -n 30 /tmp/postgres-restore.log >&2; exit 1; }
sql=(psql -X -qAt -v ON_ERROR_STOP=1 -h /var/run/postgresql -U kernscale_trading_admin -d trading)
for attempt in {1..90}; do
  state=$("${sql[@]}" -c 'SELECT pg_is_in_recovery()' 2>/dev/null || true)
  [[ "$state" == f ]] && break
  sleep 2
done
[[ "$state" == f ]] || { tail -n 30 /tmp/postgres-restore.log >&2; exit 1; }
checks=$("${sql[@]}" <<'SQL'
SELECT current_setting('server_version_num')::int = 170012,
       (SELECT string_agg(extname || '=' || extversion, ',' ORDER BY extname) = 'plpgsql=1.0' FROM pg_extension),
       EXISTS(SELECT 1 FROM pg_constraint
              WHERE contype = 'f' AND convalidated
                AND conrelid = 'public.research_runs'::regclass
                AND confrelid = 'public.research_sessions'::regclass),
       to_regclass('public.users') IS NOT NULL;
SQL
)
[[ "$checks" == 't|t|t|t' ]] || { echo 'Restored PostgreSQL or Trading schema verification failed.' >&2; exit 1; }
printf '{"backup":"%s","target":"%s","tradingSchemaReadable":true,"startedAt":"%s","acceptedAt":"%s"}\n' \
  "$label" "$target" "$start" "$(date -u +%FT%TZ)"
